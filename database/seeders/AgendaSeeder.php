<?php

namespace Database\Seeders;

use Database\Seeders\Support\AgendaFichas;
use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Horarios de atención, bloqueos de agenda y fichas del Ciclo 1.
 *
 * Tablas: horarios_atencion, bloqueos_agenda, fichas.
 *
 *  - 31 horarios semanales (30 presenciales y 1 virtual) de los 12 médicos.
 *  - Ningún horario se cruza por médico ni por consultorio: los triggers y el
 *    EXCLUDE de la base los validarían de todos modos, sin desactivarlos.
 *  - El horario de la Dra. Camacho (médica INACTIVA) está cerrado hace dos
 *    semanas: conserva su historial y no genera fichas nuevas.
 *  - 4 bloqueos, todos relativos a la fecha de ejecución: un feriado pasado,
 *    un feriado próximo, el mantenimiento del gabinete OD-2 y las vacaciones
 *    del Dr. Montaño. Las fichas que caen dentro quedan BLOQUEADA.
 *  - Fichas de la semana -4 a la +2 respecto al ancla (ver Siembra::ancla()).
 *    Desde la semana en curso no se generan fichas ya vencidas.
 */
class AgendaSeeder extends Seeder
{
    /** médico, especialidad, días (1 = lunes), inicio, fin, duración, consultorio|null, modalidad */
    private const HORARIOS = [
        ['MED-001', 'Medicina General', [1, 3], '08:00', '12:00', 20, 'Consultorio 101', 'PRESENCIAL'],
        ['MED-001', 'Geriatría', [2], '14:00', '18:00', 30, 'Consultorio 203', 'PRESENCIAL'],
        ['MED-002', 'Medicina General', [1, 3, 5], '14:00', '18:00', 20, 'Consultorio 101', 'PRESENCIAL'],
        ['MED-003', 'Medicina General', [2, 4], '08:00', '12:00', 20, 'Consultorio 102', 'PRESENCIAL'], // inactiva
        ['MED-004', 'Pediatría', [1, 3, 5], '08:00', '12:00', 30, 'Consultorio 103', 'PRESENCIAL'],
        ['MED-005', 'Pediatría', [2, 4], '14:00', '18:00', 30, 'Consultorio 103', 'PRESENCIAL'],
        ['MED-005', 'Pediatría', [6], '08:00', '12:00', 30, 'Consultorio 103', 'PRESENCIAL'],
        ['MED-006', 'Geriatría', [1, 3, 5], '14:00', '18:00', 30, 'Consultorio 203', 'PRESENCIAL'],
        ['MED-007', 'Ginecología', [1, 3], '08:00', '12:00', 30, 'Consultorio 201', 'PRESENCIAL'],
        ['MED-008', 'Ginecología', [2, 4], '08:00', '12:00', 30, 'Consultorio 201', 'PRESENCIAL'],
        ['MED-009', 'Cardiología', [1, 3], '08:00', '12:00', 30, 'Consultorio 202', 'PRESENCIAL'],
        ['MED-009', 'Cardiología', [5], '14:00', '18:00', 30, 'Consultorio 202', 'PRESENCIAL'],
        ['MED-010', 'Cardiología', [2, 4], '08:00', '12:00', 30, 'Consultorio 202', 'PRESENCIAL'],
        ['MED-010', 'Medicina General', [6], '09:00', '11:00', 20, null, 'VIRTUAL'],
        ['MED-011', 'Odontología', [1, 3], '08:00', '12:00', 30, 'Gabinete OD-1', 'PRESENCIAL'],
        ['MED-012', 'Odontología', [1, 3], '14:00', '18:00', 30, 'Gabinete OD-2', 'PRESENCIAL'],
    ];

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);

        DB::transaction(function () {
            $this->horarios();
            $this->bloqueos();
            AgendaFichas::generar(-4, 2);
        });
    }

    private function horarios(): void
    {
        $ancla = Siembra::ancla();
        $desde = $ancla->subWeeks(12)->toDateString();
        $cierreCamacho = $ancla->subDays(15)->toDateString();

        foreach (self::HORARIOS as [$medico, $especialidad, $dias, $inicio, $fin, $duracion, $consultorio, $modalidad]) {
            $me = DB::table('medicos_especialidades as me')
                ->join('medicos as m', 'm.id', '=', 'me.medico_id')
                ->join('especialidades as e', 'e.id', '=', 'me.especialidad_id')
                ->where('m.codigo', $medico)->where('e.nombre', $especialidad)
                ->value('me.id')
                ?? throw new RuntimeException("{$medico} no ejerce {$especialidad}: ejecute PersonalClinicaSeeder.");

            $consultorioId = $consultorio === null ? null
                : (Siembra::id('consultorios', ['nombre' => $consultorio])
                    ?? throw new RuntimeException("Falta el consultorio {$consultorio}: ejecute CatalogosSeeder."));

            $inactivo = $medico === 'MED-003';

            foreach ($dias as $dia) {
                Siembra::asegurar('horarios_atencion', [
                    'medico_especialidad_id' => $me,
                    'dia_semana' => $dia,
                    'hora_inicio' => $inicio.':00',
                    'hora_fin' => $fin.':00',
                ], [
                    'consultorio_id' => $consultorioId,
                    'duracion_ficha_min' => $duracion,
                    'modalidad' => $modalidad,
                    'vigente_desde' => $desde,
                    'vigente_hasta' => $inactivo ? $cierreCamacho : null,
                    'estado' => $inactivo ? 'INACTIVO' : 'ACTIVO',
                ]);
            }
        }
    }

    private function bloqueos(): void
    {
        $ancla = Siembra::ancla();
        $dia = fn (int $semana, int $dias) => $ancla->addWeeks($semana)->addDays($dias);

        $montano = Siembra::id('medicos', ['codigo' => 'MED-009'])
            ?? throw new RuntimeException('Falta MED-009: ejecute PersonalClinicaSeeder.');
        $od2 = Siembra::id('consultorios', ['nombre' => 'Gabinete OD-2'])
            ?? throw new RuntimeException('Falta el Gabinete OD-2: ejecute CatalogosSeeder.');

        $bloqueos = [
            ['CLINICA', null, null, $dia(-3, 3), $dia(-3, 3), 'Feriado departamental (simulado, histórico)'],
            ['CLINICA', null, null, $dia(2, 4), $dia(2, 4), 'Feriado nacional (simulado)'],
            ['CONSULTORIO', null, $od2, $dia(2, 2), $dia(2, 2), 'Mantenimiento del gabinete OD-2'],
            ['MEDICO', $montano, null, $dia(2, 0), $dia(2, 2), 'Vacaciones del Dr. Montaño'],
        ];

        foreach ($bloqueos as [$alcance, $medicoId, $consultorioId, $primero, $ultimo, $motivo]) {
            // Clave natural: alcance + destino + motivo (las fechas son relativas y cambian cada semana).
            Siembra::asegurar('bloqueos_agenda',
                ['alcance' => $alcance, 'medico_id' => $medicoId, 'consultorio_id' => $consultorioId, 'motivo' => $motivo],
                [
                    'inicio' => Siembra::ts($primero->setTime(0, 0, 0)),
                    'fin' => Siembra::ts($ultimo->setTime(23, 59, 59)),
                ]);
        }
    }
}
