<?php

namespace Database\Seeders\Support;

use Carbon\CarbonImmutable;
use Database\Seeders\LaboratorioCatalogoSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Inserta la atención clínica de una cita (consulta, órdenes de laboratorio con
 * sus resultados y tratamientos con sus prescripciones) validando ANTES de
 * escribir las reglas que la base no impone. Si algo no cuadra lanza
 * SiembraInvalida con un mensaje claro y no escribe nada de ese registro.
 *
 * Reglas comprobadas:
 *  - La consulta solo existe para una cita ATENDIDA (una por cita) y su inicio y
 *    fin caen dentro del horario de la ficha. Se inserta ya completa: una
 *    consulta FINALIZADA no admite UPDATE (tg_consultas_finalizada_inmutable).
 *  - La orden sale de una consulta; sus fechas siguen el orden solicitud → toma
 *    de muestra → resultado → revisión y ninguna queda en el futuro. Solo las
 *    órdenes RESULTADO_LISTO o REVISADA llevan resultados, uno por parámetro.
 *  - Cada resultado numérico guarda el ÚNICO rango vigente en la fecha del
 *    resultado para la edad en meses y el sexo del paciente en esa fecha, y la
 *    interpretación calculada con ese rango. Los de texto: sin rango, NO_APLICA.
 *  - Nunca se prescribe un medicamento de la familia de una alergia activa del
 *    paciente (FAMILIAS_ALERGIA).
 *
 * Fechas y horas: hora local de la clínica (ver Siembra).
 */
final class RegistroClinico
{
    /**
     * Sustancia registrada en alergias (tipo MEDICAMENTO) => medicamentos que no
     * se pueden prescribir a ese paciente (la propia sustancia y su familia o
     * reactividad cruzada conocida). La verificación SQL usa la misma tabla.
     */
    public const FAMILIAS_ALERGIA = [
        'Penicilina' => ['Penicilina', 'Amoxicilina', 'Ampicilina', 'Amoxicilina + ácido clavulánico', 'Dicloxacilina'],
        'Ibuprofeno' => ['Ibuprofeno', 'Diclofenaco', 'Naproxeno', 'Ketorolaco', 'Ácido acetilsalicílico'],
        'Ácido acetilsalicílico' => ['Ácido acetilsalicílico', 'Ibuprofeno', 'Diclofenaco', 'Naproxeno', 'Ketorolaco'],
        'Sulfametoxazol' => ['Sulfametoxazol + trimetoprima'],
        'Metamizol' => ['Metamizol'],
    ];

    /** @var array<string,int>|null clave de parámetro (HB, GLU…) => id */
    private static ?array $parametros = null;

    /** @var array<string,int>|null código de examen => id */
    private static ?array $examenes = null;

    /** @var array<int,list<object>>|null rangos por parámetro */
    private static ?array $rangos = null;

    // ------------------------------------------------------------------ catálogo

    public static function parametroId(string $clave): int
    {
        self::cargarCatalogo();

        return self::$parametros[$clave] ?? throw new SiembraInvalida("Falta el parámetro {$clave}: ejecute LaboratorioCatalogoSeeder.");
    }

    public static function examenId(string $codigo): int
    {
        self::cargarCatalogo();

        return self::$examenes[$codigo] ?? throw new SiembraInvalida("Falta el examen {$codigo}: ejecute LaboratorioCatalogoSeeder.");
    }

    /** @return list<string> claves de los parámetros del examen, en su orden */
    public static function parametrosDe(string $codigo): array
    {
        $parametros = LaboratorioCatalogoSeeder::EXAMENES[$codigo][3] ?? throw new SiembraInvalida("Examen desconocido: {$codigo}.");

        return array_keys($parametros);
    }

    public static function esTexto(string $clave): bool
    {
        foreach (LaboratorioCatalogoSeeder::EXAMENES as [, , , $parametros]) {
            if (isset($parametros[$clave])) {
                return $parametros[$clave][3] === 'TEXTO';
            }
        }
        throw new SiembraInvalida("Parámetro desconocido: {$clave}.");
    }

    private static function cargarCatalogo(): void
    {
        if (self::$parametros !== null) {
            return;
        }
        self::$examenes = DB::table('tipos_examen')->pluck('id', 'codigo')->map(fn ($id) => (int) $id)->all();
        self::$parametros = [];
        foreach (LaboratorioCatalogoSeeder::EXAMENES as $codigo => [, , , $parametros]) {
            foreach ($parametros as $clave => [$orden]) {
                $id = DB::table('parametros_laboratorio')
                    ->where('tipo_examen_id', self::$examenes[$codigo] ?? 0)->where('orden', $orden)->value('id');
                if ($id !== null) {
                    self::$parametros[$clave] = (int) $id;
                }
            }
        }
        self::$rangos = [];
        foreach (DB::table('rangos_referencia')->orderBy('id')->get() as $r) {
            self::$rangos[(int) $r->parametro_laboratorio_id][] = $r;
        }
    }

    // ---------------------------------------------------------- rangos y edad

    /** Meses cumplidos entre el nacimiento y la fecha. */
    public static function edadMeses(string $nacimiento, string $fecha): int
    {
        $d = date_diff(date_create($nacimiento), date_create(substr($fecha, 0, 10)));

        return $d->y * 12 + $d->m;
    }

    /**
     * Rango aplicable: el del parámetro, vigente en la fecha, cuya banda de edad
     * contiene la edad en meses y cuyo sexo es el del paciente o NULL. Debe haber
     * exactamente uno.
     */
    public static function rango(int $parametroId, int $edadMeses, string $sexo, string $fecha): object
    {
        self::cargarCatalogo();
        $fecha = substr($fecha, 0, 10);
        $aplican = array_values(array_filter(self::$rangos[$parametroId] ?? [], fn ($r) => $r->edad_min_meses <= $edadMeses && $edadMeses <= $r->edad_max_meses
            && ($r->sexo === null || $r->sexo === $sexo)
            && $r->vigente_desde <= $fecha && ($r->vigente_hasta === null || $fecha <= $r->vigente_hasta)));

        if (count($aplican) !== 1) {
            throw new SiembraInvalida("El parámetro {$parametroId} tiene ".count($aplican)." rangos aplicables para {$edadMeses} meses, sexo {$sexo}, el {$fecha} (debe ser uno).");
        }

        return $aplican[0];
    }

    /** Interpretación de un valor numérico con su rango: los límites críticos se evalúan primero. */
    public static function interpretar(object $rango, float $valor): string
    {
        return match (true) {
            $rango->critico_min !== null && $valor < (float) $rango->critico_min => 'CRITICO_BAJO',
            $rango->critico_max !== null && $valor > (float) $rango->critico_max => 'CRITICO_ALTO',
            $rango->valor_min !== null && $valor < (float) $rango->valor_min => 'BAJO',
            $rango->valor_max !== null && $valor > (float) $rango->valor_max => 'ALTO',
            default => 'NORMAL',
        };
    }

    // ------------------------------------------------------------- contexto

    /** Datos de la cita que la atención clínica necesita. */
    public static function cita(int $citaId): object
    {
        $c = DB::table('citas as c')
            ->join('fichas as f', 'f.id', '=', 'c.ficha_id')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('medicos as m', 'm.id', '=', 'me.medico_id')
            ->join('especialidades as e', 'e.id', '=', 'me.especialidad_id')
            ->join('pacientes as p', 'p.id', '=', 'c.paciente_id')
            ->join('personas as pe', 'pe.id', '=', 'p.persona_id')
            ->leftJoin('usuarios as um', 'um.persona_id', '=', 'm.persona_id')
            ->where('c.id', $citaId)
            ->first(['c.id', 'c.codigo', 'c.estado', 'c.motivo', 'c.paciente_id', 'f.fecha', 'f.hora_inicio', 'f.hora_fin',
                'm.codigo as medico', 'm.persona_id as medico_persona_id', 'um.id as medico_usuario_id',
                'e.nombre as especialidad', 'pe.fecha_nacimiento', 'pe.sexo'])
            ?? throw new SiembraInvalida("La cita {$citaId} no existe.");

        $c->inicio = Siembra::instante($c->fecha, $c->hora_inicio);
        $c->fin = Siembra::instante($c->fecha, $c->hora_fin);
        $c->edad = (int) date_diff(date_create($c->fecha_nacimiento), date_create($c->fecha))->y;
        if ($c->medico_usuario_id === null) {
            throw new SiembraInvalida("Cita {$c->codigo}: el médico {$c->medico} no tiene cuenta para registrar la atención.");
        }

        return $c;
    }

    // ---------------------------------------------------------------- consulta

    /**
     * Consulta FINALIZADA de una cita ATENDIDA. Devuelve su id, o null si ya existía
     * (idempotencia por cita_id: nunca se modifica una consulta existente).
     *
     * @param array<string,mixed> $d inicio, fin (CarbonImmutable), anamnesis, examen_fisico, signos
     *   (peso_kg, talla_cm, presion_sistolica, presion_diastolica, temperatura_c, frecuencia_cardiaca,
     *   saturacion_o2), codigo_cie10, diagnostico, indicaciones
     */
    public static function consulta(object $cita, array $d): ?int
    {
        if (Siembra::id('consultas', ['cita_id' => $cita->id]) !== null) {
            return null;
        }
        $fallo = fn (string $m) => new SiembraInvalida("Consulta de {$cita->codigo}: {$m}");

        if ($cita->estado !== 'ATENDIDA') {
            throw $fallo("la cita está {$cita->estado}; solo una cita ATENDIDA tiene consulta");
        }
        /** @var CarbonImmutable $inicio */
        $inicio = $d['inicio'];
        $fin = $d['fin'];
        if ($inicio->lt($cita->inicio) || $fin->gt($cita->fin) || $fin->lte($inicio)) {
            throw $fallo('el inicio y el fin deben caer dentro del horario de la ficha');
        }
        if ($fin->gt(Siembra::ahora())) {
            throw $fallo('una consulta FINALIZADA no puede terminar en el futuro');
        }
        $s = $d['signos'];
        if (isset($s['presion_sistolica'], $s['presion_diastolica']) && $s['presion_sistolica'] < $s['presion_diastolica']) {
            throw $fallo('la presión sistólica es menor que la diastólica');
        }
        if (! preg_match('/^[A-Z]\d{2}(\.\d{1,2})?$/', $d['codigo_cie10'])) {
            throw $fallo("código CIE-10 mal formado ({$d['codigo_cie10']})");
        }

        return (int) DB::table('consultas')->insertGetId([
            'cita_id' => $cita->id,
            'estado' => 'FINALIZADA',
            'inicio' => Siembra::ts($inicio),
            'fin' => Siembra::ts($fin),
            'anamnesis' => $d['anamnesis'],
            'examen_fisico' => $d['examen_fisico'],
            'peso_kg' => $s['peso_kg'] ?? null,
            'talla_cm' => $s['talla_cm'] ?? null,
            'presion_sistolica' => $s['presion_sistolica'] ?? null,
            'presion_diastolica' => $s['presion_diastolica'] ?? null,
            'temperatura_c' => $s['temperatura_c'] ?? null,
            'frecuencia_cardiaca' => $s['frecuencia_cardiaca'] ?? null,
            'saturacion_o2' => $s['saturacion_o2'] ?? null,
            'codigo_cie10' => $d['codigo_cie10'],
            'diagnostico' => $d['diagnostico'],
            'indicaciones' => $d['indicaciones'] ?? null,
        ]);
    }

    // ------------------------------------------------------------ laboratorio

    /**
     * Orden de laboratorio y, si su estado lo permite, sus resultados.
     *
     * @param array<string,mixed> $d codigo, examen, prioridad, estado, solicitud, toma, resultado, revision
     *   (CarbonImmutable|null), laboratorio, valores [clave => número|texto], esperado [clave => interpretación]
     * @return array<string,int> clave de parámetro => id del resultado (vacío si no hay resultados)
     */
    public static function orden(object $cita, int $consultaId, array $d): array
    {
        $codigo = $d['codigo'];
        $fallo = fn (string $m) => new SiembraInvalida("Orden {$codigo} ({$cita->codigo}): {$m}");
        if (Siembra::id('ordenes_laboratorio', ['codigo' => $codigo]) !== null) {
            return self::resultadosDeOrden($codigo);
        }

        $estado = $d['estado'];
        $conResultados = in_array($estado, ['RESULTADO_LISTO', 'REVISADA'], true);
        $ahora = Siembra::ahora();
        $consultaInicio = Siembra::desdeTimestamp((string) DB::table('consultas')->where('id', $consultaId)->value('inicio'));

        // Cronología: cada fecha exigida por el estado existe, es posterior a la anterior y no es futura.
        $exigidas = [
            'solicitud' => true,
            'toma' => in_array($estado, ['MUESTRA_TOMADA', 'RESULTADO_LISTO', 'REVISADA'], true),
            'resultado' => $conResultados,
            'revision' => $estado === 'REVISADA',
        ];
        $anterior = $consultaInicio;
        foreach ($exigidas as $campo => $exigida) {
            $valor = $d[$campo] ?? null;
            if ($exigida !== ($valor !== null)) {
                throw $fallo("la fecha de {$campo} no corresponde al estado {$estado}");
            }
            if ($valor === null) {
                continue;
            }
            if ($valor->lt($anterior) || $valor->gt($ahora)) {
                throw $fallo("la fecha de {$campo} está fuera de orden o en el futuro");
            }
            $anterior = $valor;
        }

        $parametros = self::parametrosDe($d['examen']);
        if ($conResultados && array_diff($parametros, array_keys($d['valores'] ?? [])) !== []) {
            throw $fallo('faltan valores para algún parámetro del examen');
        }
        if (! $conResultados && ! empty($d['valores'])) {
            throw $fallo("una orden {$estado} no lleva resultados");
        }

        return DB::transaction(function () use ($cita, $consultaId, $d, $codigo, $estado, $conResultados, $parametros, $fallo) {
            $ordenId = (int) DB::table('ordenes_laboratorio')->insertGetId([
                'consulta_id' => $consultaId,
                'tipo_examen_id' => self::examenId($d['examen']),
                'codigo' => $codigo,
                'fecha_solicitud' => Siembra::ts($d['solicitud']),
                'fecha_toma_muestra' => isset($d['toma']) ? Siembra::ts($d['toma']) : null,
                'prioridad' => $d['prioridad'] ?? 'NORMAL',
                'estado' => $estado,
                'laboratorio_procesador' => $d['laboratorio'] ?? null,
                'archivo_resultado' => null,
                'fecha_revision' => isset($d['revision']) ? Siembra::ts($d['revision']) : null,
            ]);

            $ids = [];
            if (! $conResultados) {
                return $ids;
            }
            $fecha = $d['resultado']->toDateString();
            $meses = self::edadMeses($cita->fecha_nacimiento, $fecha);
            foreach ($parametros as $clave) {
                $valor = $d['valores'][$clave];
                $parametroId = self::parametroId($clave);
                if (self::esTexto($clave)) {
                    $rangoId = null;
                    $interpretacion = 'NO_APLICA';
                    $numero = null;
                    $texto = (string) $valor;
                } else {
                    $rango = self::rango($parametroId, $meses, $cita->sexo, $fecha);
                    $rangoId = (int) $rango->id;
                    $numero = (float) $valor;
                    $interpretacion = self::interpretar($rango, $numero);
                    $texto = null;
                }
                $esperada = $d['esperado'][$clave] ?? null;
                if ($esperada !== null && $esperada !== $interpretacion) {
                    throw $fallo("{$clave} = {$valor} da {$interpretacion}, el escenario esperaba {$esperada}");
                }
                $ids[$clave] = (int) DB::table('resultados_laboratorio')->insertGetId([
                    'orden_laboratorio_id' => $ordenId,
                    'parametro_laboratorio_id' => $parametroId,
                    'rango_referencia_id' => $rangoId,
                    'usuario_carga_id' => $cita->medico_usuario_id,
                    'valor_numerico' => $numero,
                    'valor_texto' => $texto,
                    'interpretacion' => $interpretacion,
                    'fecha_resultado' => Siembra::ts($d['resultado']),
                    'observacion' => $d['observaciones'][$clave] ?? null,
                ]);
            }

            return $ids;
        });
    }

    /** @return array<string,int> resultados ya guardados de una orden existente */
    private static function resultadosDeOrden(string $codigo): array
    {
        $claves = [];
        foreach (self::todasLasClaves() as $clave) {
            $claves[self::parametroId($clave)] = $clave;
        }
        $ids = [];
        foreach (DB::table('resultados_laboratorio as r')->join('ordenes_laboratorio as o', 'o.id', '=', 'r.orden_laboratorio_id')
            ->where('o.codigo', $codigo)->get(['r.id', 'r.parametro_laboratorio_id']) as $r) {
            $ids[$claves[(int) $r->parametro_laboratorio_id]] = (int) $r->id;
        }

        return $ids;
    }

    /** @return list<string> */
    private static function todasLasClaves(): array
    {
        $claves = [];
        foreach (LaboratorioCatalogoSeeder::EXAMENES as [, , , $parametros]) {
            array_push($claves, ...array_keys($parametros));
        }

        return $claves;
    }

    // -------------------------------------------------------------- tratamiento

    /** Alergias medicamentosas activas del paciente (sustancias). @return list<string> */
    public static function alergiasMedicamento(int $pacienteId): array
    {
        return DB::table('alergias as a')->join('historias_clinicas as h', 'h.id', '=', 'a.historia_clinica_id')
            ->where('h.paciente_id', $pacienteId)->where('a.tipo', 'MEDICAMENTO')->where('a.activa', true)
            ->pluck('a.sustancia')->all();
    }

    /** La alergia que impide prescribir ese medicamento, o null si se puede. */
    public static function alergiaQueImpide(array $alergias, string $medicamento): ?string
    {
        foreach ($alergias as $sustancia) {
            if (in_array($medicamento, self::FAMILIAS_ALERGIA[$sustancia] ?? [$sustancia], true)) {
                return $sustancia;
            }
        }

        return null;
    }

    /**
     * Tratamiento de una consulta con sus prescripciones. Idempotente por
     * (consulta, descripción). Lanza SiembraInvalida si alguna prescripción choca
     * con una alergia activa del paciente.
     *
     * @param array<string,mixed> $d descripcion, fecha_inicio, fecha_fin, estado, respuesta,
     *   observacion, resultado_id, prescripciones [[medicamento, dosis, frecuencia, días, vía]]
     */
    public static function tratamiento(object $cita, int $consultaId, array $d): int
    {
        $existente = Siembra::id('tratamientos', ['consulta_id' => $consultaId, 'descripcion' => $d['descripcion']]);
        if ($existente !== null) {
            return $existente;
        }
        $fallo = fn (string $m) => new SiembraInvalida("Tratamiento de {$cita->codigo}: {$m}");

        $alergias = self::alergiasMedicamento($cita->paciente_id);
        foreach ($d['prescripciones'] as [$medicamento]) {
            if (($alergia = self::alergiaQueImpide($alergias, $medicamento)) !== null) {
                throw $fallo("{$medicamento} está contraindicado por la alergia a {$alergia}");
            }
        }
        if ($d['fecha_inicio'] < $cita->fecha) {
            throw $fallo('no puede empezar antes de la consulta');
        }
        if ($d['fecha_fin'] !== null && ($d['fecha_fin'] < $d['fecha_inicio'] || $d['fecha_fin'] > Siembra::ahora()->toDateString())) {
            throw $fallo('la fecha de fin es anterior al inicio o futura');
        }
        if (($d['estado'] === 'EN_CURSO') !== ($d['fecha_fin'] === null)) {
            throw $fallo('solo un tratamiento terminado (FINALIZADO o SUSPENDIDO) tiene fecha de fin');
        }

        return DB::transaction(function () use ($consultaId, $d) {
            $id = (int) DB::table('tratamientos')->insertGetId([
                'consulta_id' => $consultaId,
                'resultado_laboratorio_id' => $d['resultado_id'] ?? null,
                'descripcion' => $d['descripcion'],
                'fecha_inicio' => $d['fecha_inicio'],
                'fecha_fin' => $d['fecha_fin'],
                'estado' => $d['estado'],
                'respuesta' => $d['respuesta'],
                'observacion_evolucion' => $d['observacion'] ?? null,
            ]);
            foreach ($d['prescripciones'] as [$medicamento, $dosis, $frecuencia, $dias, $via]) {
                DB::table('prescripciones')->insert([
                    'tratamiento_id' => $id,
                    'medicamento' => $medicamento,
                    'dosis' => $dosis,
                    'frecuencia' => $frecuencia,
                    'duracion_dias' => $dias,
                    'via' => $via,
                ]);
            }

            return $id;
        });
    }

    // ---------------------------------------------------------- historia clínica

    /** Antecedente idempotente por (historia, tipo, descripción). */
    public static function antecedente(int $historiaId, string $sexo, int $edad, string $tipo, string $descripcion, ?string $inicio, bool $vigente): void
    {
        if ($tipo === 'GINECO_OBSTETRICO' && ($sexo !== 'F' || $edad < 18)) {
            throw new SiembraInvalida("Antecedente gineco-obstétrico en un paciente que no es mujer adulta (historia {$historiaId}).");
        }
        Siembra::asegurar('antecedentes',
            ['historia_clinica_id' => $historiaId, 'tipo' => $tipo, 'descripcion' => $descripcion],
            ['fecha_inicio' => $inicio, 'vigente' => $vigente]);
    }

    /** Alergia idempotente por (historia, sustancia). */
    public static function alergia(int $historiaId, string $tipo, string $sustancia, ?string $reaccion, string $severidad): void
    {
        Siembra::asegurar('alergias',
            ['historia_clinica_id' => $historiaId, 'sustancia' => $sustancia],
            ['tipo' => $tipo, 'reaccion' => $reaccion, 'severidad' => $severidad, 'activa' => true]);
    }
}
