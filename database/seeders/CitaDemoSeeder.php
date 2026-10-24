<?php

namespace Database\Seeders;

use Database\Seeders\Support\RegistroCitas;
use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Citas de demostración (CM-000001 a CM-000031) con su historial.
 *
 * Tablas: citas, historiales_cita.
 *
 * Cada cita se resuelve contra una ficha concreta (médico, especialidad, semana
 * relativa al ancla, día y hora). Si la ficha no existe o está BLOQUEADA el
 * seeder falla en voz alta: no fuerza datos. Las reglas que la base no impone
 * (edad y sexo por especialidad, responsable legal verificado, seguro propio,
 * verificado y vigente, sin citas cruzadas del paciente) las cumple este
 * escenario por construcción; se listan por cita en docs/decisiones-seeders.md.
 *
 * Semanas relativas al ancla (Siembra::ancla()): 0 = próxima semana, +1 la
 * siguiente, -2 a -4 semanas ya vencidas (histórico controlado).
 *
 * Escenarios cubiertos: ATENDIDA, PROGRAMADA, CONFIRMADA, CANCELADA con motivo,
 * INASISTENCIA marcada por el sistema, ficha liberada y reutilizada,
 * reprogramaciones (una y el máximo de dos), reasignación a otro médico
 * (REEMPLAZA), excepción autorizada por un administrador, cita virtual,
 * cita por voz, seguro vigente, médico inactivo y médico-paciente.
 */
class CitaDemoSeeder extends Seeder
{
    /**
     * codigo, paciente, médico, especialidad, [semana, día, hora], estado, creador (usuario), canal,
     * extras (motivo, previas, seguro, cancelación, reemplaza, admin, enlace, confirmar)
     */
    private function definiciones(): array
    {
        return [
            ['CM-000001', 'PAC-000001', 'MED-004', 'Pediatría', [-3, 1, '09:00'], 'ATENDIDA', 'rmendoza', 'WEB', ['motivo' => 'Control pediátrico', 'confirmar' => true]],
            ['CM-000002', 'PAC-000001', 'MED-004', 'Pediatría', [1, 1, '09:00'], 'PROGRAMADA', 'rmendoza', 'WEB', ['motivo' => 'Control de seguimiento']],
            ['CM-000003', 'PAC-000002', 'MED-005', 'Pediatría', [1, 4, '14:30'], 'PROGRAMADA', 'rmendoza', 'WEB', ['motivo' => 'Control de crecimiento', 'previas' => [[1, 2, '14:00']]]],
            ['CM-000004', 'PAC-000004', 'MED-002', 'Medicina General', [-2, 1, '14:00'], 'INASISTENCIA', 'cflores', 'WEB', ['motivo' => 'Dolor lumbar']],
            ['CM-000005', 'PAC-000004', 'MED-002', 'Medicina General', [-3, 3, '14:20'], 'INASISTENCIA', 'cflores', 'WEB', ['motivo' => 'Control de presión']],
            ['CM-000006', 'PAC-000004', 'MED-002', 'Medicina General', [-4, 5, '14:40'], 'INASISTENCIA', 'cflores', 'WEB', ['motivo' => 'Consulta general']],
            ['CM-000007', 'PAC-000004', 'MED-001', 'Medicina General', [1, 3, '08:40'], 'PROGRAMADA', 'ebarrios', 'WEB', ['motivo' => 'Reserva hecha en recepción (reserva en línea bloqueada)']],
            // Ficha liberada y reutilizada: CM-008 se registra (hace 72 h) y se cancela (hace 48 h) ANTES de que CM-009 (hace 30 h) tome la ficha.
            ['CM-000008', 'PAC-000005', 'MED-001', 'Medicina General', [1, 3, '08:00'], 'CANCELADA', 'lanez', 'WEB', ['motivo' => 'Consulta general', 'cancelacion' => 'Cambio de turno laboral', 'registro_horas' => 72, 'cancela_horas' => 48]],
            ['CM-000009', 'PAC-000006', 'MED-001', 'Medicina General', [1, 3, '08:00'], 'PROGRAMADA', 'ebarrios', 'WEB', ['motivo' => 'Ficha liberada y reutilizada', 'registro_horas' => 30]],
            ['CM-000010', 'PAC-000005', 'MED-010', 'Medicina General', [1, 6, '09:20'], 'PROGRAMADA', 'lanez', 'WEB', ['motivo' => 'Consulta virtual', 'enlace' => 'https://meet.vidasalud.test/sala/cm-000010']],
            ['CM-000011', 'PAC-000005', 'MED-003', 'Medicina General', [-3, 2, '08:00'], 'ATENDIDA', 'lanez', 'WEB', ['motivo' => 'Consulta general (médica hoy inactiva)']],
            ['CM-000012', 'PAC-000006', 'MED-003', 'Medicina General', [-4, 4, '09:00'], 'ATENDIDA', 'ebarrios', 'WEB', ['motivo' => 'Consulta general (médica hoy inactiva)', 'confirmar' => true]],
            // Reasignación: CM-013 (registrada hace 72 h) se cancela hace 52 h; CM-014 la REEMPLAZA y se registra después (hace 50 h).
            ['CM-000013', 'PAC-000011', 'MED-009', 'Cardiología', [1, 1, '08:30'], 'CANCELADA', 'rpena', 'WEB', ['motivo' => 'Control cardiológico', 'cancelacion' => 'Reasignación a otro médico por solicitud del paciente', 'registro_horas' => 72, 'cancela_horas' => 52]],
            ['CM-000014', 'PAC-000011', 'MED-010', 'Cardiología', [1, 2, '08:30'], 'PROGRAMADA', 'sortuno', 'WEB', ['motivo' => 'Control cardiológico (reasignada)', 'reemplaza' => 'CM-000013', 'seguro' => 'SI-2025-1188', 'autorizacion' => 'AUT-000014', 'registro_horas' => 50]],
            ['CM-000015', 'PAC-000010', 'MED-009', 'Cardiología', [1, 5, '15:00'], 'PROGRAMADA', 'rmendoza', 'WEB', ['motivo' => 'Chequeo cardiológico del Dr. Pérez como paciente']],
            ['CM-000016', 'PAC-000010', 'MED-010', 'Cardiología', [-2, 2, '09:00'], 'ATENDIDA', 'sortuno', 'WEB', ['motivo' => 'Chequeo cardiológico del Dr. Pérez como paciente']],
            ['CM-000017', 'PAC-000003', 'MED-005', 'Pediatría', [0, 4, '14:00'], 'PROGRAMADA', 'ebarrios', 'WEB', ['motivo' => 'Control (17 años en esta fecha)']],
            ['CM-000018', 'PAC-000003', 'MED-002', 'Medicina General', [1, 5, '14:20'], 'PROGRAMADA', 'ebarrios', 'WEB', ['motivo' => 'Primera consulta de adulto (18 años en esta fecha)']],
            ['CM-000019', 'PAC-000009', 'MED-006', 'Geriatría', [-3, 1, '15:00'], 'ATENDIDA', 'ebarrios', 'WEB', ['motivo' => 'Control geriátrico (seguro vencido: sin cobertura)']],
            ['CM-000020', 'PAC-000008', 'MED-006', 'Geriatría', [1, 5, '14:30'], 'CONFIRMADA', 'rmendoza', 'WEB', ['motivo' => 'Control geriátrico', 'seguro' => 'SA-2026-0001', 'autorizacion' => 'AUT-000020', 'previas' => [[1, 1, '14:00'], [1, 3, '14:00']]]],
            ['CM-000021', 'PAC-000008', 'MED-006', 'Geriatría', [-2, 3, '14:00'], 'ATENDIDA', 'rmendoza', 'WEB', ['motivo' => 'Control geriátrico', 'seguro' => 'SA-2026-0001', 'autorizacion' => 'AUT-000021', 'confirmar' => true]],
            ['CM-000022', 'PAC-000008', 'MED-001', 'Medicina General', [1, 3, '09:20'], 'PROGRAMADA', 'ebarrios', 'WEB', ['motivo' => 'Continuidad con su médico de cabecera', 'excepcion' => 'Paciente de 68 años solicita continuidad con su médico de cabecera; autoriza Jefatura de Operaciones.']],
            ['CM-000023', 'PAC-000007', 'MED-007', 'Ginecología', [1, 1, '09:00'], 'PROGRAMADA', 'vsuarez', 'WEB', ['motivo' => 'Control ginecológico', 'seguro' => 'PV-2026-0420', 'autorizacion' => 'AUT-000023']],
            ['CM-000024', 'PAC-000007', 'MED-008', 'Ginecología', [-2, 4, '08:00'], 'ATENDIDA', 'rmendoza', 'WEB', ['motivo' => 'Control ginecológico']],
            ['CM-000025', 'PAC-000012', 'MED-011', 'Odontología', [1, 1, '10:00'], 'PROGRAMADA', 'cvargas', 'VOZ', ['motivo' => 'Limpieza dental (reservada por voz)']],
            ['CM-000026', 'PAC-000012', 'MED-011', 'Odontología', [-2, 3, '09:00'], 'ATENDIDA', 'sortuno', 'WEB', ['motivo' => 'Revisión dental']],
            ['CM-000027', 'PAC-000006', 'MED-012', 'Odontología', [-3, 1, '14:00'], 'ATENDIDA', 'ebarrios', 'WEB', ['motivo' => 'Extracción simple']],
            ['CM-000028', 'PAC-000002', 'MED-004', 'Pediatría', [-4, 5, '10:00'], 'ATENDIDA', 'rmendoza', 'WEB', ['motivo' => 'Control de niño sano']],
            ['CM-000029', 'PAC-000009', 'MED-001', 'Geriatría', [1, 2, '14:00'], 'CONFIRMADA', 'rmendoza', 'WEB', ['motivo' => 'Control geriátrico con médico de doble especialidad']],
            // Paciente INACTIVO con historial: su única cita fue atendida antes de la baja.
            ['CM-000030', 'PAC-000015', 'MED-001', 'Geriatría', [-3, 2, '15:00'], 'ATENDIDA', 'ebarrios', 'WEB', ['motivo' => 'Control geriátrico (paciente hoy inactivo)']],
            // CU15 A1: la madre, con cuenta y responsable legal verificada, reserva para su hijo menor.
            ['CM-000031', 'PAC-000017', 'MED-004', 'Pediatría', [1, 3, '10:00'], 'PROGRAMADA', 'rterrazas', 'WEB', ['motivo' => 'Control pediátrico reservado por su madre desde su cuenta']],
        ];
    }

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);

        DB::transaction(function () {
            $usuariosMedico = [];
            foreach (PersonalClinicaSeeder::MEDICOS as $m) {
                $usuariosMedico[$m[0]] = $this->usuarioId($m[8]);
            }
            $recepcionistas = array_column(PersonalClinicaSeeder::RECEPCIONISTAS, 7);
            $adminId = Siembra::id('administradores', ['codigo' => 'ADM-002'])
                ?? throw new RuntimeException('Falta ADM-002: ejecute PersonalClinicaSeeder.');
            $citas = [];

            foreach ($this->definiciones() as [$codigo, $paciente, $medico, $esp, $ficha, $estado, $creador, $canal, $extra]) {
                // Idempotencia: una cita ya sembrada no se toca (sus fichas eran relativas a otra semana).
                if (($existente = Siembra::id('citas', ['codigo' => $codigo])) !== null) {
                    $citas[$codigo] = $existente;

                    continue;
                }

                $pacienteId = Siembra::id('pacientes', ['codigo' => $paciente])
                    ?? throw new RuntimeException("Falta {$paciente}: ejecute PacienteDemoSeeder.");
                $usuarioId = $this->usuarioId($creador);
                $porRecepcion = in_array($creador, $recepcionistas, true);

                $id = RegistroCitas::registrar([
                    'codigo' => $codigo,
                    'paciente_id' => $pacienteId,
                    'usuario_id' => $usuarioId,
                    'ficha_id' => $this->ficha($medico, $esp, ...$ficha),
                    'fichas_previas' => array_map(fn ($p) => $this->ficha($medico, $esp, ...$p), $extra['previas'] ?? []),
                    'estado' => $estado,
                    'origen' => $porRecepcion ? 'RECEPCION' : 'AUTOSERVICIO',
                    'canal' => $canal,
                    'motivo' => $extra['motivo'] ?? null,
                    'seguro_id' => isset($extra['seguro']) ? Siembra::id('seguros_paciente', ['numero_poliza' => $extra['seguro'], 'paciente_id' => $pacienteId]) : null,
                    'codigo_autorizacion' => $extra['autorizacion'] ?? null,
                    'enlace_virtual' => $extra['enlace'] ?? null,
                    'motivo_cancelacion' => $extra['cancelacion'] ?? null,
                    'admin_id' => isset($extra['excepcion']) ? $adminId : null,
                    'justificacion' => $extra['excepcion'] ?? null,
                    'reemplaza_id' => isset($extra['reemplaza']) ? ($citas[$extra['reemplaza']] ?? Siembra::id('citas', ['codigo' => $extra['reemplaza']])) : null,
                    'usuario_atiende_id' => $usuariosMedico[$medico],
                    'confirmar' => $extra['confirmar'] ?? false,
                    'registro_horas' => $extra['registro_horas'] ?? null,
                    'cancela_horas' => $extra['cancela_horas'] ?? null,
                ]);
                $citas[$codigo] = $id ?? Siembra::id('citas', ['codigo' => $codigo]);
            }
        });
    }

    private function usuarioId(string $nombreUsuario): int
    {
        return Siembra::id('usuarios', ['nombre_usuario' => $nombreUsuario])
            ?? throw new RuntimeException("Falta el usuario {$nombreUsuario}: ejecute PersonalClinicaSeeder y PacienteDemoSeeder.");
    }

    /** Ficha HABILITADA de ese médico y especialidad, en la semana, día y hora indicados. */
    private function ficha(string $medico, string $especialidad, int $semana, int $dia, string $hora): int
    {
        $fecha = Siembra::ancla()->addWeeks($semana)->addDays($dia - 1)->toDateString(); // fecha local de la clínica

        $id = DB::table('fichas as f')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('medicos as m', 'm.id', '=', 'me.medico_id')
            ->join('especialidades as e', 'e.id', '=', 'me.especialidad_id')
            ->where('m.codigo', $medico)->where('e.nombre', $especialidad)
            ->where('f.fecha', $fecha)->where('f.hora_inicio', $hora.':00')
            ->where('f.estado', 'HABILITADA')
            ->value('f.id');

        return $id !== null
            ? (int) $id
            : throw new RuntimeException("No hay ficha HABILITADA de {$medico} ({$especialidad}) el {$fecha} a las {$hora}.");
    }
}
