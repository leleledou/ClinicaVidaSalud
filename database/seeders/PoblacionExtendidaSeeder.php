<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\AgendaFichas;
use Database\Seeders\Support\RegistroCitas;
use Database\Seeders\Support\Siembra;
use Database\Seeders\Support\SiembraInvalida;
use Faker\Factory as FakerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Población extendida (nivel 2, OPCIONAL): lleva el sistema a las cantidades
 * del PUDS-UML (§6.3.2.4) para probar rendimiento y reportes. Se apoya en la demo.
 *
 *  - Pacientes PAC-000018 a PAC-000211 (211 con los 17 de la demo): 34 menores con
 *    responsable legal verificado, 37 adultos mayores, 78 usuarios en total.
 *  - Fichas de la semana -8 a la +2 y 584 citas CM-000101 a CM-000684 (615 con las
 *    de la demo), con la distribución de estados del documento:
 *    ATENDIDA 375, CANCELADA 66, CONFIRMADA 31, INASISTENCIA 60, PROGRAMADA 83.
 *
 * Determinista: Faker y mt_rand usan semillas fijas.
 *
 * COHERENCIA. Cada cita se valida en RegistroCitas::planificar() antes de
 * escribirse; si un candidato incumple una regla o una cronología se descarta y
 * se prueba otro. Además, esta clase prefiltra para no desperdiciar intentos:
 *  - la cita se registra DESPUÉS del alta del paciente;
 *  - los menores solo reservan con un responsable legal ya verificado;
 *  - el autoservicio exige la cuenta con el correo ya verificado y sin bloqueo;
 *  - el seguro debe estar verificado antes del registro y vigente en la fecha;
 *  - edad y sexo de la especialidad en la fecha de la cita; sin citas cruzadas;
 *  - máximo dos inasistencias por paciente (solo Carlos llega a tres, RN19).
 * No se fuerzan las cantidades: si faltan candidatos compatibles se crean menos
 * citas y se avisa.
 *
 * IDEMPOTENCIA. Pacientes, vínculos y seguros se reconocen por su clave natural.
 * Las citas se reconocen por su código: se cuentan las ya existentes por estado,
 * solo se crean las que faltan hasta el objetivo y nunca se toca una existente.
 * Si ya está completa, no se genera nada (ni siquiera fichas). La ejecución es
 * una sola transacción: ante un error no queda nada a medias. Limitación: si una
 * ejecución terminó con menos citas por falta de candidatos, las repeticiones
 * completan solo lo que falta con las fichas disponibles en ese momento (puede
 * distinto de lo que habría resultado en una primera ejecución completa).
 *
 * Requiere Ciclo1DemoSeeder y SEED_DEMO_PASSWORD. Nunca se ejecuta desde la
 * población mínima. Faker es una dependencia de desarrollo.
 */
class PoblacionExtendidaSeeder extends Seeder
{
    private const PRIMER_PACIENTE = 18;
    private const ULTIMO_PACIENTE = 211;
    private const PRIMERA_CITA = 101;
    private const ULTIMA_CITA = 684;
    private const SEMILLA = 20261024;
    private const REPROGRAMACIONES = 17;

    /** Objetivo de citas extendidas por estado (demo + extendida = distribución del PUDS-UML). */
    private const OBJETIVO = [
        'ATENDIDA' => 364, 'INASISTENCIA' => 57, 'CANCELADA' => 64,
        'PROGRAMADA' => 70, 'CONFIRMADA' => 29,
    ];

    /** De las canceladas, 48 de 64 se crean sobre fichas pasadas y el resto sobre futuras. */
    private const CANCELADAS_PASADAS = [48, 64];

    /** @var array<string,list<string>> */
    private const MOTIVOS = [
        'Medicina General' => ['Consulta general', 'Control de presión arterial', 'Dolor de cabeza persistente', 'Certificado médico', 'Control de diabetes'],
        'Pediatría' => ['Control de niño sano', 'Fiebre y tos', 'Vacunación y control', 'Control de crecimiento'],
        'Geriatría' => ['Control geriátrico', 'Revisión de medicación', 'Evaluación de movilidad'],
        'Ginecología' => ['Control ginecológico', 'Control prenatal', 'Consulta de planificación familiar'],
        'Odontología' => ['Limpieza dental', 'Revisión dental', 'Dolor de muela', 'Ortodoncia: control'],
        'Cardiología' => ['Control cardiológico', 'Electrocardiograma de control', 'Palpitaciones'],
    ];

    private const MOTIVOS_CANCELACION = [
        'Cambio de horario laboral', 'Se resolvió el problema de salud', 'Viaje imprevisto',
        'Prefiere otra fecha', 'Enfermedad en la familia',
    ];

    private const GRUPOS = ['O_POS', 'A_POS', 'B_POS', 'AB_POS', 'O_NEG', 'A_NEG', 'B_NEG', 'AB_NEG', 'DESCONOCIDO'];

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);
        if (Siembra::id('citas', ['codigo' => 'CM-000001']) === null) {
            throw new RuntimeException('Ejecute primero Ciclo1DemoSeeder: la población extendida se apoya en su personal y agenda.');
        }
        $hash = Siembra::hashDesdeEntorno('SEED_DEMO_PASSWORD')
            ?? throw new RuntimeException('Defina SEED_DEMO_PASSWORD en su .env local.');

        DB::transaction(function () use ($hash) {
            $this->pacientes($hash);

            $plan = $this->planDeCitas();
            if ($plan['pendientes'] === 0) {
                $this->command?->info('Población extendida: las citas ya estaban completas; no se genera nada.');

                return;
            }

            AgendaFichas::generar(-8, 2);
            $creadas = $this->citas($plan);
            $this->command?->info("Población extendida: {$creadas} citas nuevas.");
            if ($creadas < $plan['pendientes']) {
                $this->command?->warn('Faltaron candidatos compatibles: se crearon '.$creadas.' de '.$plan['pendientes'].' citas (no se fuerzan las cantidades).');
            }
        });
    }

    // ------------------------------------------------------------------ pacientes

    private function pacientes(string $hash): void
    {
        $faker = FakerFactory::create('es_ES');
        $hoy = Siembra::ahora()->startOfDay();
        $ancla = Siembra::ancla();
        $recepcionistas = DB::table('recepcionistas')->orderBy('codigo')->pluck('id')->all();
        $aseguradoras = DB::table('aseguradoras')->orderBy('nit')->pluck('id')->all();
        if (count($recepcionistas) < 3 || count($aseguradoras) < 3) {
            throw new RuntimeException('Faltan recepcionistas o aseguradoras: ejecute los seeders previos.');
        }

        for ($i = self::PRIMER_PACIENTE; $i <= self::ULTIMO_PACIENTE; $i++) {
            $faker->seed(7000 + $i);
            $n = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $sexo = $i % 2 === 0 ? 'F' : 'M';
            $nombres = $sexo === 'M' ? $faker->firstNameMale() : $faker->firstNameFemale();
            $paterno = $faker->lastName();
            $materno = $faker->lastName();

            $menor = $i <= 47;                        // 30 menores
            $mayor = $i >= 48 && $i <= 81;            // 34 adultos mayores
            $soloCertificado = $i <= 24;              // 7 niños con solo certificado de nacimiento
            if ($menor) {
                $edad = $soloCertificado ? ($i * 3) % 10 : 10 + ($i * 5) % 8;
            } elseif ($mayor) {
                $edad = 60 + ($i * 3) % 29;
            } else {
                $edad = 18 + ($i * 5) % 42;
            }
            $nacimiento = $hoy->subYears($edad)->subDays(($i * 37) % 360)->toDateString();

            $conCuenta = $i >= 48 && $i <= 100;       // 53 cuentas de adultos
            $autoregistro = $i >= 48 && $i <= 88;     // 41 se autorregistraron (46 con la demo)
            $usuario = $conCuenta ? "pac{$i}" : null;
            // El alta ocurre entre 40 y 129 días antes del ancla: toda cita se registra después de ella.
            $registro = $ancla->subDays(40 + ($i * 13) % 90)->setTime(8, 30);

            $personaId = Siembra::persona([
                'tipo_documento' => $soloCertificado ? 'CERTIFICADO_NACIMIENTO' : 'CI',
                'numero_documento' => $soloCertificado ? "CN-{$n}" : '993'.$n,
                'expedido' => ['CB', 'LP', 'SC', 'OR'][$i % 4],
                'nombres' => $nombres, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                'fecha_nacimiento' => $nacimiento, 'sexo' => $sexo,
                'telefono' => $faker->numerify('7#######'),
                'correo' => $usuario !== null ? "{$usuario}@vidasalud.test" : null,
                'direccion' => $faker->streetAddress().', Cochabamba',
            ]);

            $pacienteId = Siembra::asegurar('pacientes', ['persona_id' => $personaId], [
                'recepcionista_registra_id' => $autoregistro ? null : $recepcionistas[$i % 3],
                'codigo' => 'PAC-'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'estado' => 'ACTIVO',
                'fecha_registro' => Siembra::ts($registro),
            ]);
            Siembra::asegurar('historias_clinicas', ['paciente_id' => $pacienteId], [
                'fecha_apertura' => $registro->toDateString(),
                'grupo_sanguineo' => self::GRUPOS[$i % 9],
            ]);
            if ($usuario !== null) {
                Siembra::usuario($personaId, 'PACIENTE', $usuario, "{$usuario}@vidasalud.test", $hash, [
                    'correo_verificado_en' => Siembra::ts($registro->addMinutes(10)),
                ]);
            }

            // Vínculos: responsable legal a todo menor; contactos de emergencia a parte de los demás.
            // Siempre se verifican un día DESPUÉS del alta (nunca antes de que exista el paciente).
            if ($menor) {
                $this->vinculo($faker, $pacienteId, '994'.$n, $paterno, $i % 2 === 0 ? 'F' : 'M', $i % 2 === 0 ? 'Madre' : 'Padre', true, true, $recepcionistas[$i % 3], $registro, $i);
                if ($i % 2 === 0) {
                    $this->vinculo($faker, $pacienteId, '995'.$n, $paterno, 'M', 'Abuelo', true, false, $recepcionistas[($i + 1) % 3], $registro, $i);
                }
            } elseif (($mayor && $i % 2 === 0) || (! $mayor && $i % 6 === 0)) {
                $verifica = $i % 10 < 7 ? $recepcionistas[$i % 3] : null;      // algunos contactos quedan sin verificar
                $this->vinculo($faker, $pacienteId, '996'.$n, $paterno, $i % 3 === 0 ? 'M' : 'F', $mayor ? 'Hijo/a' : 'Cónyuge', true, false, $verifica, $registro, $i);
            }

            // 49 seguros vigentes (con los 5 de la demo suman 54, como el documento), verificados tras el alta.
            if (! $menor && $i % 3 === 0 && $i <= 192) {
                Siembra::asegurar('seguros_paciente', ['paciente_id' => $pacienteId, 'numero_poliza' => "EXT-{$n}"], [
                    'aseguradora_id' => $aseguradoras[$i % 3],
                    'recepcionista_verifica_id' => $recepcionistas[$i % 3],
                    'plan' => 'Plan Estándar',
                    'titular' => trim("{$nombres} {$paterno} {$materno}"),
                    'vigente_desde' => $ancla->subDays(250)->toDateString(),
                    'vigente_hasta' => $ancla->addDays(165)->toDateString(),
                    'fecha_verificacion' => Siembra::ts($registro->addDays(2)->setTime(11, 0)),
                    'estado' => 'ACTIVO',
                ]);
            }
        }
    }

    private function vinculo($faker, int $pacienteId, string $ci, string $apellido, string $sexo, string $parentesco, bool $emergencia, bool $legal, ?int $verifica, CarbonImmutable $registro, int $i): void
    {
        $persona = Siembra::persona([
            'tipo_documento' => 'CI', 'numero_documento' => $ci, 'expedido' => 'CB',
            'nombres' => $sexo === 'M' ? $faker->firstNameMale() : $faker->firstNameFemale(),
            'apellido_paterno' => $apellido, 'apellido_materno' => $faker->lastName(),
            'fecha_nacimiento' => Siembra::ahora()->startOfDay()->subYears(30 + $i % 25)->subDays($i % 300)->toDateString(),
            'sexo' => $sexo, 'telefono' => $faker->numerify('7#######'),
        ]);
        Siembra::asegurar('vinculos_paciente',
            ['paciente_id' => $pacienteId, 'persona_id' => $persona, 'estado' => 'ACTIVO'],
            [
                'recepcionista_verifica_id' => $verifica,
                'parentesco' => $parentesco,
                'es_contacto_emergencia' => $emergencia,
                'es_responsable_legal' => $legal,
                'fecha_verificacion' => $verifica === null ? null : Siembra::ts($registro->addDay()->setTime(10, 0)),
            ]);
    }

    // ---------------------------------------------------------------------- citas

    /**
     * Qué falta por crear. Cuenta las citas extendidas ya existentes por estado
     * (independiente del reloj) y devuelve los cupos restantes.
     *
     * @return array{pendientes:int, pasadas:list<string>, futuras:list<string>, existentes:array<string,true>, reprogramaciones:int}
     */
    private function planDeCitas(): array
    {
        $codigoMin = $this->codigo(self::PRIMERA_CITA);
        $codigoMax = $this->codigo(self::ULTIMA_CITA);

        // Se cuenta SOLO por estado, nunca por «pasada/futura»: esa clasificación depende del
        // reloj y cambiaría entre ejecuciones (una cancelada futura pasaría a pasada).
        $existentes = [];
        $cuenta = [];
        foreach (DB::table('citas')->where('codigo', '>=', $codigoMin)->where('codigo', '<=', $codigoMax)->get(['codigo', 'estado']) as $f) {
            $existentes[$f->codigo] = true;
            $cuenta[$f->estado] = ($cuenta[$f->estado] ?? 0) + 1;
        }

        $faltan = [];
        foreach (self::OBJETIVO as $estado => $objetivo) {
            $faltan[$estado] = max(0, $objetivo - ($cuenta[$estado] ?? 0));
        }
        [$parte, $total] = self::CANCELADAS_PASADAS;
        $canceladasPasadas = (int) round($faltan['CANCELADA'] * $parte / $total);

        $pasadas = array_merge(
            array_fill(0, $faltan['ATENDIDA'], 'ATENDIDA'),
            array_fill(0, $faltan['INASISTENCIA'], 'INASISTENCIA'),
            array_fill(0, $canceladasPasadas, 'CANCELADA'),
        );
        $futuras = array_merge(
            array_fill(0, $faltan['PROGRAMADA'], 'PROGRAMADA'),
            array_fill(0, $faltan['CONFIRMADA'], 'CONFIRMADA'),
            array_fill(0, $faltan['CANCELADA'] - $canceladasPasadas, 'CANCELADA'),
        );

        $reprogramadas = DB::table('historiales_cita as h')->join('citas as c', 'c.id', '=', 'h.cita_id')
            ->where('c.codigo', '>=', $codigoMin)->where('c.codigo', '<=', $codigoMax)
            ->whereNotNull('h.ficha_anterior_id')->count();

        return [
            'pendientes' => count($pasadas) + count($futuras),
            'pasadas' => $pasadas,
            'futuras' => $futuras,
            'existentes' => $existentes,
            'reprogramaciones' => max(0, self::REPROGRAMACIONES - $reprogramadas),
        ];
    }

    private function codigo(int $numero): string
    {
        return 'CM-'.str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }

    /** @param array{pendientes:int, pasadas:list<string>, futuras:list<string>, existentes:array<string,true>, reprogramaciones:int} $plan */
    private function citas(array $plan): int
    {
        mt_srand(self::SEMILLA);
        $ahora = Siembra::ahora();
        $ancla = Siembra::ancla();
        $ahoraTs = $ahora->getTimestamp();

        $pacientes = $this->cargarPacientes();
        $ocupado = $this->cargarOcupacion();
        $inasistencias = DB::table('citas')->where('estado', 'INASISTENCIA')
            ->selectRaw('paciente_id, count(*) as n')->groupBy('paciente_id')->pluck('n', 'paciente_id')
            ->map(fn ($n) => (int) $n)->all();
        $recepcion = DB::table('usuarios')->where('rol_id', Siembra::rolId('RECEPCIONISTA'))->orderBy('id')->pluck('id')->all();
        $usuarioMedico = DB::table('usuarios as u')->join('medicos as m', 'm.persona_id', '=', 'u.persona_id')->pluck('u.id', 'm.id')->all();

        $fichas = DB::table('fichas as f')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('especialidades as e', 'e.id', '=', 'me.especialidad_id')
            ->where('f.estado', 'HABILITADA')
            ->whereBetween('f.fecha', [$ancla->subWeeks(8)->toDateString(), $ancla->addWeeks(3)->subDay()->toDateString()])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('citas as c')
                ->whereColumn('c.ficha_id', 'f.id')->where('c.estado', '<>', 'CANCELADA'))
            ->orderBy('f.id')
            ->get(['f.id', 'f.fecha', 'f.hora_inicio', 'f.hora_fin', 'me.id as me', 'me.medico_id', 'e.nombre as esp',
                'e.edad_minima as emin', 'e.edad_maxima as emax', 'e.sexo_permitido as esexo']);

        $pasadas = [];
        $futuras = [];
        $porMe = [];
        foreach ($fichas as $f) {
            $f->ini = Siembra::instante($f->fecha, $f->hora_inicio)->getTimestamp();
            $f->fin = Siembra::instante($f->fecha, $f->hora_fin)->getTimestamp();
            $porMe[$f->me][] = $f;
            if ($f->ini < $ahoraTs - 2 * 3600) {
                $pasadas[] = $f;
            } elseif ($f->ini > $ahoraTs + 3 * 3600) {
                $futuras[] = $f;
            }
        }
        shuffle($pasadas);
        shuffle($futuras);
        $estadosPasados = $plan['pasadas'];
        $estadosFuturos = $plan['futuras'];
        shuffle($estadosPasados);
        shuffle($estadosFuturos);

        $existentes = $plan['existentes'];
        $numero = self::PRIMERA_CITA - 1;
        // Siguiente código libre (sin avanzar hasta confirmar que la cita se creó).
        $proximo = function () use (&$numero, $existentes): array {
            $k = $numero;
            do {
                $k++;
                $codigo = $this->codigo($k);
            } while (isset($existentes[$codigo]));

            return [$k, $codigo];
        };

        $reprogramaciones = $plan['reprogramaciones'];
        $creadas = 0;

        foreach ([[$estadosPasados, $pasadas], [$estadosFuturos, $futuras]] as [$estados, $pool]) {
            $punto = 0;
            foreach ($estados as $estado) {
                $hecha = false;
                while ($punto < count($pool)) {
                    $ficha = $pool[$punto++];
                    if (isset($ficha->usada)) {
                        continue; // ya fue ficha final o anterior de otra cita
                    }
                    // Instante en que se registraría la cita por defecto (ver RegistroCitas::planificar).
                    $registro = min($ficha->ini - 5 * 86400, $ahoraTs - 26 * 3600);
                    $paciente = $this->elegirPaciente($pacientes, $ficha, $estado, $ocupado, $inasistencias, $registro);
                    if ($paciente === null) {
                        continue;
                    }
                    $pid = $paciente['id'];

                    $autoservicio = $paciente['usuario'] !== null && ! $paciente['bloqueada']
                        && $paciente['correo'] !== null && $paciente['correo'] + 300 <= $registro
                        && ($inasistencias[$pid] ?? 0) < 3 && mt_rand(1, 1000) <= 540;
                    $usuarioId = $autoservicio ? $paciente['usuario'] : $recepcion[mt_rand(0, count($recepcion) - 1)];
                    $seguro = $this->seguroVigente($paciente, $ficha->fecha, $registro);
                    $conSeguro = $seguro !== null && mt_rand(1, 100) <= 75;

                    $previa = null;
                    if ($reprogramaciones > 0 && $estado !== 'CANCELADA' && mt_rand(1, 100) <= 6) {
                        $previa = $this->fichaPrevia($porMe[$ficha->me], $ficha, $estado, $ahoraTs);
                    }

                    [$k, $codigo] = $proximo();
                    $motivos = self::MOTIVOS[$ficha->esp];
                    try {
                        $id = RegistroCitas::registrar([
                            'codigo' => $codigo,
                            'paciente_id' => $pid,
                            'usuario_id' => $usuarioId,
                            'ficha_id' => $ficha->id,
                            'fichas_previas' => $previa === null ? [] : [$previa->id],
                            'estado' => $estado,
                            'origen' => $autoservicio ? 'AUTOSERVICIO' : 'RECEPCION',
                            'canal' => mt_rand(1, 1000) <= 77 ? 'VOZ' : 'WEB',
                            'motivo' => $motivos[mt_rand(0, count($motivos) - 1)],
                            'seguro_id' => $conSeguro ? $seguro : null,
                            'codigo_autorizacion' => $conSeguro ? 'AUT-'.substr($codigo, 3) : null,
                            'motivo_cancelacion' => $estado === 'CANCELADA' ? self::MOTIVOS_CANCELACION[mt_rand(0, count(self::MOTIVOS_CANCELACION) - 1)] : null,
                            'usuario_atiende_id' => $usuarioMedico[$ficha->medico_id] ?? $usuarioId,
                            'confirmar' => mt_rand(1, 100) <= 60,
                        ]);
                    } catch (SiembraInvalida) {
                        continue; // el candidato no cumple una regla o cronología: se prueba otro
                    }
                    if ($id === null) {
                        throw new RuntimeException("El código {$codigo} ya existía: el plan de citas es inconsistente.");
                    }

                    $numero = $k;
                    $creadas++;
                    $ficha->usada = true;
                    if ($previa !== null) {
                        $previa->usada = true; // su reserva original ya no puede asignarse a otro paciente
                        $reprogramaciones--;
                    }
                    if ($estado !== 'CANCELADA') {
                        $ocupado[$pid][] = [$ficha->ini, $ficha->fin];
                    }
                    if ($estado === 'INASISTENCIA') {
                        $inasistencias[$pid] = ($inasistencias[$pid] ?? 0) + 1;
                    }
                    $hecha = true;
                    break;
                }
                if (! $hecha) {
                    break; // sin más fichas compatibles para esta lista
                }
            }
        }

        return $creadas;
    }

    /**
     * Pacientes activos con lo necesario para prefiltrar. Los instantes son marcas de tiempo.
     *
     * @return list<array<string,mixed>>
     */
    private function cargarPacientes(): array
    {
        $rolPaciente = Siembra::rolId('PACIENTE');
        $filas = DB::table('pacientes as p')
            ->join('personas as pe', 'pe.id', '=', 'p.persona_id')
            ->leftJoin('usuarios as u', fn ($j) => $j->on('u.persona_id', '=', 'p.persona_id')->where('u.rol_id', $rolPaciente)->where('u.estado', 'ACTIVO'))
            ->where('p.estado', 'ACTIVO')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('medicos as m')->whereColumn('m.persona_id', 'p.persona_id'))
            ->orderBy('p.id')
            ->get(['p.id', 'p.fecha_registro as reg', 'pe.fecha_nacimiento as nac', 'pe.sexo',
                'u.id as usuario', 'u.correo_verificado_en as correo', 'u.bloqueado_hasta as bloqueo']);

        // Primera verificación de un responsable legal activo de cada paciente.
        $responsables = DB::table('vinculos_paciente')
            ->where('estado', 'ACTIVO')->where('es_responsable_legal', true)->whereNotNull('fecha_verificacion')
            ->selectRaw('paciente_id, min(fecha_verificacion) as v')->groupBy('paciente_id')->pluck('v', 'paciente_id')->all();

        $seguros = [];
        foreach (DB::table('seguros_paciente')->where('estado', 'ACTIVO')->whereNotNull('fecha_verificacion')
            ->get(['id', 'paciente_id', 'vigente_desde', 'vigente_hasta', 'fecha_verificacion']) as $s) {
            $seguros[$s->paciente_id][] = [$s->id, $s->vigente_desde, $s->vigente_hasta, Siembra::desdeTimestamp($s->fecha_verificacion)->getTimestamp()];
        }

        return $filas->map(fn ($p) => [
            'id' => (int) $p->id,
            'reg' => Siembra::desdeTimestamp($p->reg)->getTimestamp(),
            'nac' => $p->nac,
            'sexo' => $p->sexo,
            'usuario' => $p->usuario === null ? null : (int) $p->usuario,
            'correo' => $p->correo === null ? null : Siembra::desdeTimestamp($p->correo)->getTimestamp(),
            'bloqueada' => $p->bloqueo !== null,
            'responsable' => isset($responsables[$p->id]) ? Siembra::desdeTimestamp($responsables[$p->id])->getTimestamp() : null,
            'seguros' => $seguros[$p->id] ?? [],
        ])->all();
    }

    /** @return array<int,list<array{int,int}>> intervalos de las citas activas existentes por paciente */
    private function cargarOcupacion(): array
    {
        $ocupado = [];
        $filas = DB::table('citas as c')->join('fichas as f', 'f.id', '=', 'c.ficha_id')
            ->where('c.estado', '<>', 'CANCELADA')->get(['c.paciente_id', 'f.fecha', 'f.hora_inicio', 'f.hora_fin']);
        foreach ($filas as $f) {
            $ocupado[$f->paciente_id][] = [
                Siembra::instante($f->fecha, $f->hora_inicio)->getTimestamp(),
                Siembra::instante($f->fecha, $f->hora_fin)->getTimestamp(),
            ];
        }

        return $ocupado;
    }

    /**
     * @param list<array<string,mixed>> $pacientes
     * @param array<int,list<array{int,int}>> $ocupado
     * @param array<int,int> $inasistencias
     * @return array<string,mixed>|null
     */
    private function elegirPaciente(array $pacientes, object $ficha, string $estado, array $ocupado, array $inasistencias, int $registro): ?array
    {
        for ($intento = 0; $intento < 40; $intento++) {
            $p = $pacientes[mt_rand(0, count($pacientes) - 1)];

            if ($p['reg'] + 1800 > $registro) {
                continue; // el paciente aún no existía cuando se habría registrado la cita
            }
            $edad = date_diff(date_create($p['nac']), date_create($ficha->fecha))->y;   // edad en la fecha de la cita (R-EDAD)
            if (($ficha->emin !== null && $edad < $ficha->emin) || ($ficha->emax !== null && $edad > $ficha->emax)) {
                continue;
            }
            if ($ficha->esexo !== null && $ficha->esexo !== $p['sexo']) {
                continue;
            }
            if ($edad < 18 && ($p['responsable'] === null || $p['responsable'] + 300 > $registro)) {
                continue; // RN16: responsable legal verificado ANTES de la reserva
            }
            if ($estado === 'INASISTENCIA' && ($inasistencias[$p['id']] ?? 0) >= 2) {
                continue; // solo Carlos llega a tres (RN19)
            }
            foreach ($ocupado[$p['id']] ?? [] as [$ini, $fin]) {
                if ($ficha->ini < $fin && $ficha->fin > $ini) {
                    continue 2; // RN17: sin citas cruzadas
                }
            }

            return $p;
        }

        return null;
    }

    /** Seguro verificado antes del registro y vigente en la fecha de la cita (RN20), o null. */
    private function seguroVigente(array $paciente, string $fecha, int $registro): ?int
    {
        foreach ($paciente['seguros'] as [$id, $desde, $hasta, $verificado]) {
            if ($desde <= $fecha && $fecha <= $hasta && $verificado + 300 <= $registro) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * Ficha anterior de una reprogramación: del mismo horario/médico, entre 1 y 4
     * días antes de la ficha final (la cita se registró cinco días antes), sin
     * usar, y para citas futuras también futura.
     *
     * @param list<object> $delMismoMedico
     */
    private function fichaPrevia(array $delMismoMedico, object $final, string $estado, int $ahoraTs): ?object
    {
        $candidatas = [];
        foreach ($delMismoMedico as $f) {
            $dias = ($final->ini - $f->ini) / 86400;
            if ($f->id !== $final->id && ! isset($f->usada) && $dias >= 1 && $dias <= 4
                && ($estado === 'ATENDIDA' || $f->ini > $ahoraTs + 86400)) {
                $candidatas[] = $f;
            }
        }

        return $candidatas === [] ? null : $candidatas[mt_rand(0, count($candidatas) - 1)];
    }
}
