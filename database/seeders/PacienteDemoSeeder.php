<?php

namespace Database\Seeders;

use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Diecisiete pacientes de demostración que cubren los escenarios del Ciclo 1.
 *
 * Tablas: personas, pacientes, historias_clinicas (CU10 la abre al registrar
 * al paciente), vinculos_paciente, aseguradoras/seguros_paciente y usuarios
 * (solo los pacientes con cuenta).
 *
 *  PAC-000001 Pedro      8 años, solo certificado de nacimiento, responsable legal verificado
 *  PAC-000002 Sofía      5 años, sin documento, responsable legal verificado
 *  PAC-000003 Lucas      17 años en la semana 0 y 18 desde la +1 (caso de la docente, fecha relativa)
 *  PAC-000004 Carlos     adulto con cuenta; tres inasistencias bloquean su reserva en línea (RN19)
 *  PAC-000005 Luciana    se autorregistró por la web, correo verificado, sin seguro
 *  PAC-000006 Andrés     adulto sin cuenta y sin seguro; documento con complemento
 *  PAC-000007 Valeria    mujer con cuenta y seguro vigente (Ginecología)
 *  PAC-000008 Fernanda   adulta mayor, seguro vigente, contacto de emergencia verificado
 *  PAC-000009 Mario      adulto mayor sin cuenta, seguro vencido, contacto sin verificar
 *  PAC-000010 Dr. Pérez  médico que también es paciente (misma persona que MED-001)
 *  PAC-000011 Ricardo    con cuenta y seguro vigente (Cardiología)
 *  PAC-000012 Camila     autorregistrada, seguro pendiente de verificación
 *  PAC-000013 Gabriel    cuenta bloqueada temporalmente por intentos fallidos (CU01)
 *  PAC-000014 Marcela    autorregistro con el correo aún sin verificar (CU12)
 *  PAC-000015 Julio      paciente INACTIVO con una cita atendida en su historial (CU10)
 *  PAC-000016 Roxana     paciente con cuenta y responsable legal verificada de PAC-000017
 *  PAC-000017 Mateo      menor de 7 años cuya cita reserva su madre desde su propia cuenta (CU15 A1)
 *
 * Los pacientes con documento tienen CI 991xxxx; los responsables, 992xxxx.
 * Las edades se calculan respecto a la fecha de ejecución; la de Lucas, respecto al ancla.
 */
class PacienteDemoSeeder extends Seeder
{
    /**
     * codigo, nombres, paterno, materno, sexo, nacimiento [años, días | fecha],
     * documento [tipo, número, complemento], registra (REC-xxx|null), usuario|null, grupo, días desde el registro
     */
    private const PACIENTES = [
        ['PAC-000001', 'Pedro', 'Mamani', 'Condori', 'M', [8, 120], ['CERTIFICADO_NACIMIENTO', 'CN-0001', null], 'REC-001', null, 'O_POS', 60],
        // Sin documento: la fecha de nacimiento identifica a la persona, por eso es un literal FIJO (5 años en 2026).
        ['PAC-000002', 'Sofía', 'Medina', 'Rocha', 'F', '2021-08-14', [null, null, null], 'REC-001', null, 'A_POS', 45],
        ['PAC-000003', 'Lucas', 'Rojas', 'Vaca', 'M', ['cumple18', 7], ['CI', '9910003', null], 'REC-002', null, 'B_POS', 90],
        ['PAC-000004', 'Carlos', 'Flores', 'Medina', 'M', [34, 12], ['CI', '9910004', null], 'REC-001', 'cflores', 'O_POS', 150],
        ['PAC-000005', 'Luciana', 'Áñez', 'Áñez', 'F', [31, 100], ['CI', '9910005', null], null, 'lanez', 'A_NEG', 110],
        ['PAC-000006', 'Andrés', 'Barba', 'Pérez', 'M', [45, 200], ['CI', '9910006', '1B'], 'REC-002', null, 'DESCONOCIDO', 80],
        ['PAC-000007', 'Valeria', 'Suárez', 'Roca', 'F', [29, 60], ['CI', '9910007', null], null, 'vsuarez', 'AB_POS', 70],
        ['PAC-000008', 'Fernanda', 'Arce', 'Salvatierra', 'F', [68, 30], ['CI', '9910008', null], 'REC-001', null, 'A_POS', 120],
        ['PAC-000009', 'Mario', 'Mamani', 'Cabrera', 'M', [72, 250], ['CI', '9910009', null], 'REC-002', null, 'O_NEG', 100],
        ['PAC-000010', null, null, null, null, null, ['CI', '9900201', null], 'REC-003', null, 'B_NEG', 30], // persona de MED-001
        ['PAC-000011', 'Ricardo', 'Peña', 'Lozano', 'M', [52, 90], ['CI', '9910011', null], 'REC-003', 'rpena', 'A_POS', 130],
        ['PAC-000012', 'Camila', 'Vargas', 'Ibáñez', 'F', [24, 15], ['CI', '9910012', null], null, 'cvargas', 'O_POS', 20],
        // Escenarios de cuenta y estado (CU01, CU04, CU10, CU12, CU15 A1). El 12.º elemento son opciones.
        ['PAC-000013', 'Gabriel', 'Ibáñez', 'Mendoza', 'M', [38, 50], ['CI', '9910013', null], null, 'gibanez', 'O_POS', 55,
            ['cuenta' => ['intentos_fallidos' => 5, 'bloqueo_dias' => 14]]],          // cuenta bloqueada temporalmente
        ['PAC-000014', 'Marcela', 'Soria', 'Peña', 'F', [27, 200], ['CI', '9910014', null], null, 'msoria', 'A_POS', 7,
            ['cuenta' => ['correo_pendiente' => true]]],                              // autorregistro con correo sin verificar
        ['PAC-000015', 'Julio', 'Cárdenas', 'Ríos', 'M', [66, 30], ['CI', '9910015', null], 'REC-002', null, 'B_POS', 100,
            ['estado' => 'INACTIVO']],                                                // paciente dado de baja con historial
        ['PAC-000016', 'Roxana', 'Terrazas', 'Gil', 'F', [36, 120], ['CI', '9910016', null], 'REC-001', 'rterrazas', 'AB_NEG', 50],
        ['PAC-000017', 'Mateo', 'Terrazas', 'Gil', 'M', [7, 60], ['CERTIFICADO_NACIMIENTO', 'CN-0003', null], 'REC-001', null, 'AB_NEG', 50], // menor; su madre Roxana tiene cuenta
    ];

    /** Personas que no son pacientes: responsables legales y contactos de emergencia. */
    private const RESPONSABLES = [
        'G1' => ['Teresa', 'Condori', 'Flores', 'F', '1988-02-20', '9920001'],
        'G2' => ['Jimena', 'Rocha', 'Tapia', 'F', '1990-06-11', '9920002'],
        'G3' => ['Mirna', 'Vaca', 'Salvatierra', 'F', '1982-08-03', '9920003'],
        'G4' => ['Natalia', 'Arce', 'Salvatierra', 'F', '1991-01-16', '9920004'],
        'G5' => ['Rubén', 'Mamani', 'Quispe', 'M', '1975-04-09', '9920005'],
    ];

    /** paciente, responsable, parentesco, contacto de emergencia, responsable legal, verifica (REC-xxx|null), días desde la verificación */
    private const VINCULOS = [
        ['PAC-000001', 'G1', 'Madre', true, true, 'REC-001', 58],
        ['PAC-000002', 'G2', 'Madre', true, true, 'REC-001', 44],
        ['PAC-000003', 'G3', 'Madre', true, true, 'REC-002', 88],
        ['PAC-000008', 'G4', 'Hija', true, false, 'REC-001', 118],
        ['PAC-000009', 'G5', 'Hijo', true, false, null, 0], // contacto aún sin verificar
        ['PAC-000017', 'PAC-000016', 'Madre', true, true, 'REC-001', 30], // responsable legal con cuenta (CU15 A1)
    ];

    /** paciente, aseguradora, póliza, plan, desde/hasta (días respecto del ancla), verifica (REC-xxx|null), estado */
    private const SEGUROS = [
        ['PAC-000008', 'Seguros Andinos Salud', 'SA-2026-0001', 'Plan Oro', -300, 200, 'REC-001', 'ACTIVO'],
        ['PAC-000007', 'Previsora Vida Segura', 'PV-2026-0420', 'Plan Familiar', -200, 165, 'REC-002', 'ACTIVO'],
        ['PAC-000011', 'Salud Integral Mutual', 'SI-2025-1188', 'Plan Cardio', -400, 300, 'REC-003', 'ACTIVO'],
        ['PAC-000009', 'Seguros Andinos Salud', 'SA-2024-0777', 'Plan Básico', -700, -120, 'REC-002', 'INACTIVO'], // vencido
        ['PAC-000012', 'Previsora Vida Segura', 'PV-2026-0999', 'Plan Dental', -30, 335, null, 'ACTIVO'], // pendiente de verificar
    ];

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);
        $hash = Siembra::hashDesdeEntorno('SEED_DEMO_PASSWORD')
            ?? throw new RuntimeException('Defina SEED_DEMO_PASSWORD en su .env local para sembrar cuentas de demostración.');

        DB::transaction(function () use ($hash) {
            $pacientes = $this->pacientes($hash);
            $this->vinculos($pacientes);
            $this->seguros($pacientes);
        });
    }

    /** @return array<string,int> código de paciente => id */
    private function pacientes(string $hash): array
    {
        $hoy = Siembra::ahora()->startOfDay();
        $ancla = Siembra::ancla();
        $ids = [];

        $ahora = Siembra::ahora();

        foreach (self::PACIENTES as $fila) {
            [$codigo, $nombres, $paterno, $materno, $sexo, $nacimiento, $doc, $registra, $usuario, $grupo, $diasRegistro] = $fila;
            $opciones = $fila[11] ?? [];
            [$tipo, $numero, $complemento] = $doc;

            if ($nombres === null) {
                // Médico que también es paciente: se reutiliza la persona de MED-001.
                $personaId = Siembra::id('personas', ['tipo_documento' => $tipo, 'numero_documento' => $numero, 'complemento' => $complemento])
                    ?? throw new RuntimeException('Falta MED-001: ejecute PersonalClinicaSeeder antes.');
            } else {
                // Literal fijo | ['cumple18', N]: cumple 18 años N días después del ancla (17 en la semana 0,
                // 18 desde la +1) | [años, días]: relativo a hoy. Solo se usa al CREAR la persona; los pacientes
                // con documento se reconocen por él, así que la fecha relativa no rompe la idempotencia.
                $fecha = match (true) {
                    is_string($nacimiento) => $nacimiento,
                    $nacimiento[0] === 'cumple18' => $ancla->addDays($nacimiento[1])->subYears(18)->toDateString(),
                    default => $hoy->subYears($nacimiento[0])->subDays($nacimiento[1])->toDateString(),
                };
                $personaId = Siembra::persona([
                    'tipo_documento' => $tipo, 'numero_documento' => $numero, 'complemento' => $complemento,
                    'expedido' => $numero !== null ? 'CB' : null,
                    'nombres' => $nombres, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                    'fecha_nacimiento' => $fecha, 'sexo' => $sexo,
                    'telefono' => '7011'.substr($codigo, -4),
                    'correo' => $usuario !== null ? "{$usuario}@vidasalud.test" : null,
                    'direccion' => 'Calle Ejemplo '.(100 + (int) substr($codigo, -2)).', Cochabamba',
                ]);
            }

            $recId = $registra === null ? null : Siembra::id('recepcionistas', ['codigo' => $registra])
                ?? throw new RuntimeException("Falta la recepcionista {$registra}: ejecute PersonalClinicaSeeder antes.");
            $registro = $ancla->subDays($diasRegistro)->setTime(9, 0);
            if ($registro->gt($ahora->subHours(2))) {
                $registro = $ahora->subHours(2); // un alta reciente nunca queda en el futuro
            }

            $pacienteId = Siembra::asegurar('pacientes', ['persona_id' => $personaId], [
                'recepcionista_registra_id' => $recId,
                'codigo' => $codigo,
                'estado' => $opciones['estado'] ?? 'ACTIVO',
                'fecha_registro' => Siembra::ts($registro),
            ]);
            $ids[$codigo] = $pacienteId;

            // CU10: al registrar al paciente se abre su historia clínica.
            Siembra::asegurar('historias_clinicas', ['paciente_id' => $pacienteId], [
                'fecha_apertura' => $registro->toDateString(),
                'grupo_sanguineo' => $grupo,
            ]);

            if ($usuario !== null) {
                $cuenta = $opciones['cuenta'] ?? [];
                // Por defecto el correo queda verificado a los 10 minutos del alta; con
                // `correo_pendiente` sigue sin verificar (el paciente aún no puede reservar en línea).
                $extra = [
                    'notificar_por_correo' => true,
                    'correo_verificado_en' => isset($cuenta['correo_pendiente']) ? null : Siembra::ts($registro->addMinutes(10)),
                ];
                if (isset($cuenta['intentos_fallidos'])) {
                    // Bloqueo temporal de demostración: en operación normal dura minutos_bloqueo_login.
                    $extra['intentos_fallidos'] = $cuenta['intentos_fallidos'];
                    $extra['bloqueado_hasta'] = Siembra::ts($ahora->addDays($cuenta['bloqueo_dias']));
                }
                Siembra::usuario($personaId, 'PACIENTE', $usuario, "{$usuario}@vidasalud.test", $hash, $extra);
            }
        }

        return $ids;
    }

    /** @param array<string,int> $pacientes */
    private function vinculos(array $pacientes): void
    {
        $ancla = Siembra::ancla();
        $personas = [];
        foreach (self::RESPONSABLES as $clave => [$nombres, $paterno, $materno, $sexo, $nacimiento, $ci]) {
            $personas[$clave] = Siembra::persona([
                'tipo_documento' => 'CI', 'numero_documento' => $ci, 'expedido' => 'CB',
                'nombres' => $nombres, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                'fecha_nacimiento' => $nacimiento, 'sexo' => $sexo, 'telefono' => '7012'.substr($ci, -4),
                'correo' => Str::lower(Str::ascii($nombres)).'.'.Str::lower(Str::ascii($paterno)).'@vidasalud.test',
            ]);
        }

        // El responsable es una persona de RESPONSABLES o, si empieza con «PAC-», la persona de ese paciente (con cuenta).
        $personaDe = fn (string $clave): int => $personas[$clave]
            ?? (int) DB::table('pacientes')->where('id', $pacientes[$clave])->value('persona_id');

        foreach (self::VINCULOS as [$codigo, $responsable, $parentesco, $emergencia, $legal, $verifica, $dias]) {
            $recId = $verifica === null ? null : Siembra::id('recepcionistas', ['codigo' => $verifica]);
            // Clave natural: el vínculo activo del paciente con esa persona (índice único parcial).
            Siembra::asegurar('vinculos_paciente',
                ['paciente_id' => $pacientes[$codigo], 'persona_id' => $personaDe($responsable), 'estado' => 'ACTIVO'],
                [
                    'recepcionista_verifica_id' => $recId,
                    'parentesco' => $parentesco,
                    'es_contacto_emergencia' => $emergencia,
                    'es_responsable_legal' => $legal,
                    'fecha_verificacion' => $recId === null ? null : Siembra::ts($ancla->subDays($dias)->setTime(10, 0)),
                ]);
        }
    }

    /** La verificación de un seguro no puede ser anterior al alta del paciente: se retrasa a un día después. */
    private function despuesDelAlta(int $pacienteId, \Carbon\CarbonImmutable $deseada): \Carbon\CarbonImmutable
    {
        $alta = Siembra::desdeTimestamp((string) DB::table('pacientes')->where('id', $pacienteId)->value('fecha_registro'));
        $minima = $alta->addDay()->setTime(11, 0);

        return $deseada->lt($minima) ? $minima : $deseada;
    }

    /** @param array<string,int> $pacientes */
    private function seguros(array $pacientes): void
    {
        $ancla = Siembra::ancla();

        foreach (self::SEGUROS as [$codigo, $aseguradora, $poliza, $plan, $desde, $hasta, $verifica, $estado]) {
            $aseguradoraId = Siembra::id('aseguradoras', ['nombre' => $aseguradora])
                ?? throw new RuntimeException("Falta la aseguradora {$aseguradora}: ejecute CatalogosSeeder.");
            $recId = $verifica === null ? null : Siembra::id('recepcionistas', ['codigo' => $verifica]);
            $titular = DB::table('pacientes as p')->join('personas as pe', 'pe.id', '=', 'p.persona_id')
                ->where('p.id', $pacientes[$codigo])
                ->selectRaw("pe.nombres || ' ' || pe.apellido_paterno || ' ' || coalesce(pe.apellido_materno, '') as nombre")
                ->value('nombre');

            Siembra::asegurar('seguros_paciente',
                ['paciente_id' => $pacientes[$codigo], 'numero_poliza' => $poliza],
                [
                    'aseguradora_id' => $aseguradoraId,
                    'recepcionista_verifica_id' => $recId,
                    'plan' => $plan,
                    'titular' => trim($titular),
                    'vigente_desde' => $ancla->addDays($desde)->toDateString(),
                    'vigente_hasta' => $ancla->addDays($hasta)->toDateString(),
                    // Se verifica a los 2 días de iniciada la póliza, pero nunca antes del alta del paciente.
                    'fecha_verificacion' => $recId === null ? null : Siembra::ts($this->despuesDelAlta(
                        $pacientes[$codigo], $ancla->addDays($desde)->addDays(2)->setTime(11, 0))),
                    'estado' => $estado,
                ]);
        }
    }
}
