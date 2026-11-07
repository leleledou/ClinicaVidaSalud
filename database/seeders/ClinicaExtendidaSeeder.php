<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\CatalogoClinico;
use Database\Seeders\Support\RegistroClinico;
use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Población clínica extendida (perfil extendido): la atención de las citas de
 * PoblacionExtendidaSeeder.
 *
 *  - Antecedentes y alergias para parte de los pacientes PAC-000018 a PAC-000211,
 *    coherentes con la edad y el sexo (gineco-obstétricos solo en mujeres adultas,
 *    crónicos del adulto desde los 30 años, etc.).
 *  - Consulta FINALIZADA para ~70 % de las citas ATENDIDAS CM-000101 a CM-000684,
 *    con diagnóstico CIE-10 acorde a la especialidad y al motivo, signos vitales
 *    según la edad, y tratamiento cuando el diagnóstico lo requiere. Se excluyen
 *    los pacientes de los casos guiados (ClinicaDemoSeeder::PACIENTES_CASO).
 *  - Unas 150 órdenes de laboratorio (con las de la demo) con sus resultados;
 *    algunas quedan SOLICITADAS, con MUESTRA_TOMADA o ANULADAS, sin resultados.
 *
 * Determinismo e idempotencia: cada paciente y cada cita usan su propia semilla
 * (SEMILLA + número), así que la decisión sobre uno no depende de los demás ni
 * de lo que ya exista. Lo existente se reconoce por su clave natural y no se
 * toca: un paciente que ya tiene antecedentes o alergias, o una cita que ya
 * tiene consulta, se saltan.
 *
 * Las reglas (consulta solo en cita ATENDIDA y dentro de la ficha, rango por
 * edad y sexo en la fecha del resultado, sin medicamentos de una familia a la
 * que el paciente es alérgico…) las valida RegistroClinico antes de escribir.
 * Un incumplimiento detiene toda la siembra (una sola transacción).
 */
class ClinicaExtendidaSeeder extends Seeder
{
    private const SEMILLA = 20261108;
    private const PRIMER_PACIENTE = 18;
    private const ULTIMO_PACIENTE = 211;
    private const PRIMERA_CITA = 101;
    private const ULTIMA_CITA = 684;

    /** Porcentaje de citas ATENDIDAS con consulta. */
    private const CON_CONSULTA = 70;

    /** Exámenes que procesa un laboratorio externo (resultado en dos días). */
    private const EXTERNOS = ['TSH', 'HBA1C'];

    /** Exámenes con ayuno o primera orina: la muestra se toma a la mañana siguiente. */
    private const EN_AYUNAS = ['GLUCOSA', 'PERFIL_LIPIDICO', 'EGO'];

    /** [tipo, descripción, edad mínima, edad máxima, sexo|null, vigente, medicación asociada|null] */
    private const ANTECEDENTES = [
        ['FAMILIAR', 'Padre con asma bronquial', 0, 120, null, true, null],
        ['FAMILIAR', 'Abuelo con diabetes mellitus tipo 2', 0, 120, null, true, null],
        ['FAMILIAR', 'Madre con hipertensión arterial', 0, 120, null, true, null],
        ['FAMILIAR', 'Hermano con epilepsia', 0, 120, null, true, null],
        ['PATOLOGICO', 'Bronquiolitis en el primer año de vida', 1, 11, null, false, null],
        ['PATOLOGICO', 'Varicela', 3, 120, null, false, null],
        ['PATOLOGICO', 'Asma bronquial leve intermitente', 4, 40, null, true, null],
        ['PATOLOGICO', 'Neumonía adquirida en la comunidad, resuelta', 2, 120, null, false, null],
        ['QUIRURGICO', 'Herniorrafia umbilical', 1, 120, null, true, null],
        ['QUIRURGICO', 'Apendicectomía', 10, 120, null, true, null],
        ['QUIRURGICO', 'Colecistectomía laparoscópica', 25, 120, null, true, null],
        ['QUIRURGICO', 'Cesárea', 20, 120, 'F', true, null],
        ['QUIRURGICO', 'Cirugía de cataratas', 60, 120, null, true, null],
        ['CRONICO', 'Hipertensión arterial', 30, 120, null, true, 'Losartán 50 mg al día'],
        ['CRONICO', 'Diabetes mellitus tipo 2', 30, 120, null, true, 'Metformina 850 mg cada 12 horas'],
        ['CRONICO', 'Hipotiroidismo', 25, 120, 'F', true, 'Levotiroxina 50 mcg al día'],
        ['CRONICO', 'Gastritis crónica', 18, 120, null, true, null],
        ['CRONICO', 'Artrosis de rodillas', 55, 120, null, true, null],
        ['CRONICO', 'Enfermedad renal crónica estadio 2', 60, 120, null, true, null],
        ['HABITO', 'Tabaquismo: 10 cigarrillos al día', 18, 120, null, true, null],
        ['HABITO', 'Consumo de alcohol los fines de semana', 18, 70, null, true, null],
        ['HABITO', 'Sedentarismo', 18, 120, null, true, null],
        ['HABITO', 'Exfumador desde hace más de 10 años', 40, 120, null, false, null],
        ['GINECO_OBSTETRICO', 'G0 P0; ciclos regulares', 18, 45, 'F', true, null],
        ['GINECO_OBSTETRICO', 'G2 P2; ciclos regulares', 22, 49, 'F', true, null],
        ['GINECO_OBSTETRICO', 'G3 P2 C1; ligadura de trompas', 30, 120, 'F', true, null],
        ['GINECO_OBSTETRICO', 'Menopausia a los 50 años; G4 P4', 52, 120, 'F', true, null],
    ];

    /** [tipo, sustancia, reacción, severidad, edad mínima, peso] */
    private const ALERGIAS = [
        ['MEDICAMENTO', 'Penicilina', 'Urticaria generalizada', 'MODERADA', 0, 4],
        ['MEDICAMENTO', 'Penicilina', 'Anafilaxia', 'GRAVE', 0, 1],
        ['MEDICAMENTO', 'Ibuprofeno', 'Broncoespasmo', 'MODERADA', 0, 2],
        ['MEDICAMENTO', 'Ácido acetilsalicílico', 'Angioedema', 'GRAVE', 12, 1],
        ['MEDICAMENTO', 'Sulfametoxazol', 'Erupción cutánea', 'LEVE', 0, 1],
        ['MEDICAMENTO', 'Metamizol', 'Hipotensión', 'MODERADA', 12, 1],
        ['ALIMENTO', 'Maní', 'Urticaria', 'MODERADA', 0, 2],
        ['ALIMENTO', 'Mariscos', 'Urticaria y vómitos', 'MODERADA', 5, 2],
        ['ALIMENTO', 'Leche de vaca', 'Diarrea y cólicos', 'LEVE', 0, 1],
        ['AMBIENTAL', 'Ácaros del polvo', 'Rinitis', 'LEVE', 0, 3],
        ['AMBIENTAL', 'Polen', 'Rinoconjuntivitis', 'LEVE', 0, 2],
        ['AMBIENTAL', 'Pelo de gato', 'Estornudos y prurito ocular', 'LEVE', 0, 1],
        ['OTRO', 'Látex', 'Dermatitis de contacto', 'LEVE', 12, 1],
    ];

    private const EVOLUCION = [
        'MEJORO' => 'Evolución favorable: los síntomas cedieron al completar el tratamiento.',
        'SIN_CAMBIO' => 'Sin mejoría significativa; se reevaluará en el próximo control.',
        'EMPEORO' => 'Empeoramiento de los síntomas; se indica una nueva evaluación.',
    ];

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);
        if (Siembra::id('citas', ['codigo' => $this->codigo(self::PRIMERA_CITA)]) === null) {
            throw new RuntimeException('Ejecute primero PoblacionExtendidaSeeder: la atención clínica usa sus citas.');
        }
        if (Siembra::id('consultas', ['cita_id' => Siembra::id('citas', ['codigo' => 'CM-000001'])]) === null) {
            throw new RuntimeException('Ejecute primero ClinicaDemoSeeder: el escenario demo va antes que la población amplia.');
        }

        DB::transaction(function () {
            [$conHistoria, $nuevasHistorias] = $this->historias();
            [$consultas, $ordenes, $tratamientos] = $this->atenciones();
            $this->command?->info("Clínica extendida: {$nuevasHistorias} historias completadas ({$conHistoria} ya lo estaban), {$consultas} consultas, {$ordenes} órdenes y {$tratamientos} tratamientos nuevos.");
        });
    }

    private function codigo(int $n): string
    {
        return 'CM-'.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    // ------------------------------------------------------- historia clínica

    /** @return array{int,int} historias que ya tenían datos y historias completadas ahora */
    private function historias(): array
    {
        $hoy = Siembra::ahora()->toDateString();
        $filas = DB::table('pacientes as p')->join('personas as pe', 'pe.id', '=', 'p.persona_id')
            ->join('historias_clinicas as h', 'h.paciente_id', '=', 'p.id')
            ->whereBetween('p.codigo', ['PAC-'.str_pad((string) self::PRIMER_PACIENTE, 6, '0', STR_PAD_LEFT), 'PAC-'.str_pad((string) self::ULTIMO_PACIENTE, 6, '0', STR_PAD_LEFT)])
            ->orderBy('p.codigo')
            ->get(['p.codigo', 'h.id as historia', 'pe.sexo', 'pe.fecha_nacimiento']);

        $ya = 0;
        $nuevas = 0;
        foreach ($filas as $p) {
            $tiene = DB::table('antecedentes')->where('historia_clinica_id', $p->historia)->exists()
                || DB::table('alergias')->where('historia_clinica_id', $p->historia)->exists();
            if ($tiene) {
                $ya++;

                continue;
            }
            mt_srand(self::SEMILLA + (int) substr($p->codigo, 4) * 7);
            $edad = (int) date_diff(date_create($p->fecha_nacimiento), date_create($hoy))->y;
            $escribio = false;

            // Algo más de la mitad tiene antecedentes (los mayores casi siempre).
            if (mt_rand(1, 100) <= ($edad >= 60 ? 85 : 50)) {
                $posibles = array_values(array_filter(self::ANTECEDENTES, fn ($a) => $a[2] <= $edad && $edad <= $a[3] && ($a[4] === null || $a[4] === $p->sexo)));
                shuffle($posibles);
                foreach (array_slice($posibles, 0, mt_rand(1, $edad >= 60 ? 3 : 2)) as [$tipo, $descripcion, , , , $vigente, $medicacion]) {
                    $inicio = $this->inicioAntecedente($p->fecha_nacimiento, $edad, $tipo);
                    RegistroClinico::antecedente($p->historia, $p->sexo, $edad, $tipo, $descripcion, $inicio, $vigente);
                    if ($medicacion !== null) {
                        RegistroClinico::antecedente($p->historia, $p->sexo, $edad, 'MEDICACION_ACTUAL', $medicacion, $inicio, true);
                    }
                    $escribio = true;
                }
            }

            // Uno de cada cinco tiene alguna alergia.
            if (mt_rand(1, 100) <= 20) {
                $alergia = $this->alergiaAlAzar($edad);
                RegistroClinico::alergia($p->historia, $alergia[0], $alergia[1], $alergia[2], $alergia[3]);
                $escribio = true;
            }
            $nuevas += $escribio ? 1 : 0;
        }

        return [$ya, $nuevas];
    }

    /** Fecha de inicio entre el nacimiento y hoy; los familiares y gineco-obstétricos no la llevan. */
    private function inicioAntecedente(string $nacimiento, int $edad, string $tipo): ?string
    {
        if (in_array($tipo, ['FAMILIAR', 'GINECO_OBSTETRICO'], true) || $edad < 1) {
            return null;
        }
        $desde = CarbonImmutable::parse($nacimiento)->addYears(intdiv($edad, 2));

        return $desde->addDays(mt_rand(0, max(0, (int) $desde->diffInDays(Siembra::ahora()) - 30)))->toDateString();
    }

    /** @return array{string,string,string,string} */
    private function alergiaAlAzar(int $edad): array
    {
        $bolsa = [];
        foreach (self::ALERGIAS as $a) {
            if ($edad >= $a[4]) {
                array_push($bolsa, ...array_fill(0, $a[5], $a));
            }
        }

        return array_slice($bolsa[mt_rand(0, count($bolsa) - 1)], 0, 4);
    }

    // -------------------------------------------------------------- atención

    /** @return array{int,int,int} consultas, órdenes y tratamientos nuevos */
    private function atenciones(): array
    {
        // Los pacientes de los casos guiados de la demo quedan fuera: una consulta al azar (por ejemplo,
        // otra anemia de Pedro entre sus controles) contradiría la evolución que muestra ese caso.
        $citas = DB::table('citas as c')->join('pacientes as p', 'p.id', '=', 'c.paciente_id')
            ->where('c.estado', 'ATENDIDA')
            ->whereBetween('c.codigo', [$this->codigo(self::PRIMERA_CITA), $this->codigo(self::ULTIMA_CITA)])
            ->whereNotIn('p.codigo', ClinicaDemoSeeder::PACIENTES_CASO)
            ->orderBy('c.codigo')->pluck('c.id', 'c.codigo');

        $totales = [0, 0, 0];
        foreach ($citas as $codigo => $citaId) {
            if (Siembra::id('consultas', ['cita_id' => $citaId]) !== null) {
                continue;
            }
            $n = (int) substr($codigo, 3);
            mt_srand(self::SEMILLA + $n);
            if (mt_rand(1, 100) > self::CON_CONSULTA) {
                continue; // cita atendida sin consulta registrada en el sistema
            }
            $cita = RegistroClinico::cita((int) $citaId);
            [$o, $t] = $this->atender($cita, $n);
            $totales[0]++;
            $totales[1] += $o;
            $totales[2] += $t;
        }

        return $totales;
    }

    /** @return array{int,int} órdenes y tratamientos creados */
    private function atender(object $cita, int $n): array
    {
        $clave = CatalogoClinico::elegir($cita->especialidad, $cita->motivo, $cita->edad, $cita->sexo);
        [$cie, $diagnostico, , , $perfilSignos, $anamnesis, $examen, $indicaciones, $examenes, $perfilLab, $plan] = CatalogoClinico::DIAGNOSTICOS[$clave];

        $inicio = $cita->inicio->addMinutes(mt_rand(0, 3));
        $fin = $cita->fin->subMinutes(mt_rand(1, 4));
        $consultaId = RegistroClinico::consulta($cita, [
            'inicio' => $inicio, 'fin' => $fin,
            'anamnesis' => CatalogoClinico::texto($anamnesis),
            'examen_fisico' => $examen,
            'signos' => CatalogoClinico::signos($cita->edad, $cita->sexo, $perfilSignos),
            'codigo_cie10' => $cie, 'diagnostico' => $diagnostico, 'indicaciones' => $indicaciones,
        ]);

        $ordenes = 0;
        foreach ($examenes as [$examenCodigo, $probabilidad]) {
            if (mt_rand(1, 100) > $probabilidad) {
                continue;
            }
            $ordenes++;
            RegistroClinico::orden($cita, $consultaId, $this->orden($cita, $fin, 'LAB-'.substr($cita->codigo, 3).'-'.$ordenes, $examenCodigo, $perfilLab));
        }

        $tratamientos = 0;
        if ($plan !== null) {
            RegistroClinico::tratamiento($cita, $consultaId, $this->tratamiento($cita, $plan));
            $tratamientos++;
        }

        return [$ordenes, $tratamientos];
    }

    /** @return array<string,mixed> datos de la orden para RegistroClinico::orden */
    private function orden(object $cita, CarbonImmutable $finConsulta, string $codigo, string $examen, ?string $perfil): array
    {
        $ahora = Siembra::ahora();
        $urgente = mt_rand(1, 100) <= 8;
        $externo = in_array($examen, self::EXTERNOS, true);

        $r = mt_rand(1, 100);
        $estado = match (true) {
            $r <= 6 => 'SOLICITADA',        // el paciente aún no fue a tomarse la muestra
            $r <= 11 => 'MUESTRA_TOMADA',   // muestra en proceso
            $r <= 13 => 'ANULADA',
            $r <= 38 => 'RESULTADO_LISTO',  // el médico todavía no lo revisó
            default => 'REVISADA',
        };

        $solicitud = $finConsulta->subMinute();
        $toma = in_array($examen, self::EN_AYUNAS, true) && ! $urgente
            ? $finConsulta->startOfDay()->addDay()->setTime(7, 0)->addMinutes(mt_rand(0, 75))
            : $finConsulta->addMinutes($urgente ? mt_rand(5, 15) : mt_rand(15, 40));
        $resultado = $externo
            ? $toma->startOfDay()->addDays(2)->setTime(10, 0)->addMinutes(mt_rand(0, 300))
            : $toma->addMinutes($urgente ? mt_rand(45, 90) : mt_rand(120, 300));
        $revision = $resultado->addMinutes(mt_rand(120, 1800));

        // Nada en el futuro: si un paso aún no llegó, la orden se queda en el estado anterior.
        if (in_array($estado, ['RESULTADO_LISTO', 'REVISADA'], true) && $revision->gt($ahora)) {
            $estado = 'RESULTADO_LISTO';
        }
        if (in_array($estado, ['RESULTADO_LISTO', 'REVISADA'], true) && $resultado->gt($ahora)) {
            $estado = 'MUESTRA_TOMADA';
        }
        if ($estado === 'MUESTRA_TOMADA' && $toma->gt($ahora)) {
            $estado = 'SOLICITADA';
        }

        $conResultados = in_array($estado, ['RESULTADO_LISTO', 'REVISADA'], true);
        $valores = [];
        if ($conResultados) {
            $fecha = $resultado->toDateString();
            $meses = RegistroClinico::edadMeses($cita->fecha_nacimiento, $fecha);
            foreach (RegistroClinico::parametrosDe($examen) as $clave) {
                if (RegistroClinico::esTexto($clave)) {
                    $valores[$clave] = CatalogoClinico::textoResultado($clave, $perfil);
                } else {
                    $rango = RegistroClinico::rango(RegistroClinico::parametroId($clave), $meses, $cita->sexo, $fecha);
                    $valores[$clave] = CatalogoClinico::valor($clave, $rango, CatalogoClinico::objetivo($clave, $perfil));
                }
            }
        }

        return [
            'codigo' => $codigo,
            'examen' => $examen,
            'prioridad' => $urgente ? 'URGENTE' : 'NORMAL',
            'estado' => $estado,
            'solicitud' => $solicitud,
            'toma' => in_array($estado, ['SOLICITADA', 'ANULADA'], true) ? null : $toma,
            'resultado' => $conResultados ? $resultado : null,
            'revision' => $estado === 'REVISADA' ? $revision : null,
            'laboratorio' => $estado === 'SOLICITADA' || $estado === 'ANULADA' ? null
                : ($externo ? 'Laboratorio de Referencia Andino (externo)' : 'Laboratorio clínico Clínica Vida Salud'),
            'valores' => $valores,
        ];
    }

    /**
     * Tratamiento del diagnóstico. Una prescripción contraindicada por una alergia
     * del paciente se cambia por su alternativa (o se omite si no la tiene).
     *
     * @param array{string,bool,list<array>,array<string,array|null>} $plan
     * @return array<string,mixed>
     */
    private function tratamiento(object $cita, array $plan): array
    {
        [$descripcion, $cronico, $prescripciones, $alternativas] = $plan;
        $alergias = RegistroClinico::alergiasMedicamento($cita->paciente_id);

        $finales = [];
        foreach ($prescripciones as $p) {
            if (RegistroClinico::alergiaQueImpide($alergias, $p[0]) !== null) {
                $p = $alternativas[$p[0]] ?? null;
                if ($p === null || RegistroClinico::alergiaQueImpide($alergias, $p[0]) !== null) {
                    continue; // sin alternativa segura: no se prescribe
                }
            }
            $finales[] = $p;
        }

        $inicio = CarbonImmutable::parse($cita->fecha);
        $hoy = Siembra::ahora()->startOfDay();
        $dias = max([1, ...array_map(fn ($p) => $p[3], $finales)]);
        $termina = $inicio->addDays($finales === [] ? 0 : $dias);
        $antiguedad = (int) $inicio->diffInDays($hoy);

        $azar = mt_rand(1, 100);
        if ($azar <= 4 && $finales !== [] && $inicio->addDays(3)->lte($hoy)) {
            [$estado, $fin, $respuesta, $obs] = ['SUSPENDIDO', $inicio->addDays(mt_rand(2, 3)), mt_rand(0, 1) ? 'EMPEORO' : 'SIN_CAMBIO',
                'Suspendido por intolerancia gástrica; se reevalúa el esquema.'];
        } elseif ($cronico) {
            $respuesta = $antiguedad < 28 ? 'PENDIENTE' : $this->respuesta(55, 35);
            [$estado, $fin, $obs] = ['EN_CURSO', null, $respuesta === 'PENDIENTE' ? null : 'Tratamiento crónico en curso. '.self::EVOLUCION[$respuesta]];
        } elseif ($termina->lte($hoy)) {
            $respuesta = $this->respuesta(78, 17);
            [$estado, $fin, $obs] = ['FINALIZADO', $termina, self::EVOLUCION[$respuesta]];
        } else {
            [$estado, $fin, $respuesta, $obs] = ['EN_CURSO', null, 'PENDIENTE', null];
        }

        return [
            'descripcion' => $descripcion,
            'resultado_id' => null,
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $fin?->toDateString(),
            'estado' => $estado,
            'respuesta' => $respuesta,
            'observacion' => $obs,
            'prescripciones' => $finales,
        ];
    }

    private function respuesta(int $mejoro, int $sinCambio): string
    {
        $r = mt_rand(1, 100);

        return $r <= $mejoro ? 'MEJORO' : ($r <= $mejoro + $sinCambio ? 'SIN_CAMBIO' : 'EMPEORO');
    }
}
