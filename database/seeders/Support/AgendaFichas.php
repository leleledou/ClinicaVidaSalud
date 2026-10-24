<?php

namespace Database\Seeders\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Generación de fichas a partir de los horarios de atención (CU09, paso 8).
 *
 *  - Es idempotente: no vuelve a crear una ficha (horario, fecha, hora_inicio).
 *  - Respeta la vigencia del horario.
 *  - Marca BLOQUEADA la ficha que cae dentro de un bloqueo de agenda aplicable.
 *  - Desde la semana en curso no genera fichas ya vencidas; las semanas
 *    anteriores se cargan como histórico (necesario para citas atendidas).
 *  - Fechas y horas son locales de la clínica (ver Siembra): se comparan con
 *    «ahora» y con los bloqueos en la misma zona.
 */
final class AgendaFichas
{
    /**
     * @param int $semanaDesde semana relativa al ancla (0 = próxima semana, -4 = hace cuatro)
     * @param int $semanaHasta semana relativa inclusive
     * @return int fichas nuevas insertadas
     */
    public static function generar(int $semanaDesde, int $semanaHasta): int
    {
        $ancla = Siembra::ancla();
        $ahora = Siembra::ahora();
        $inicioActual = Siembra::inicioSemanaActual();
        $desde = $ancla->addWeeks($semanaDesde);
        $hasta = $ancla->addWeeks($semanaHasta + 1)->subDay();

        $horarios = DB::table('horarios_atencion as h')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->get(['h.id', 'h.dia_semana', 'h.hora_inicio', 'h.hora_fin', 'h.duracion_ficha_min',
                'h.consultorio_id', 'h.vigente_desde', 'h.vigente_hasta', 'h.estado', 'me.medico_id']);

        $bloqueos = DB::table('bloqueos_agenda')->get()->map(fn ($b) => [
            'alcance' => $b->alcance,
            'medico_id' => $b->medico_id,
            'consultorio_id' => $b->consultorio_id,
            'inicio' => Siembra::desdeTimestamp($b->inicio),   // hora local de la clínica
            'fin' => Siembra::desdeTimestamp($b->fin),
        ])->all();

        $existentes = [];
        $filas = DB::table('fichas')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['horario_atencion_id', 'fecha', 'hora_inicio']);
        foreach ($filas as $f) {
            $existentes[$f->horario_atencion_id.'|'.$f->fecha.'|'.substr($f->hora_inicio, 0, 5)] = true;
        }

        $nuevas = [];
        for ($dia = $desde; $dia->lte($hasta); $dia = $dia->addDay()) {
            foreach ($horarios as $h) {
                if ((int) $h->dia_semana !== $dia->dayOfWeekIso) {
                    continue;
                }
                // Un horario INACTIVO solo aporta fichas históricas si tiene cierre de vigencia.
                if ($h->estado !== 'ACTIVO' && $h->vigente_hasta === null) {
                    continue;
                }
                if ($dia->toDateString() < $h->vigente_desde
                    || ($h->vigente_hasta !== null && $dia->toDateString() > $h->vigente_hasta)) {
                    continue;
                }

                $minutos = self::aMinutos($h->hora_inicio);
                $fin = self::aMinutos($h->hora_fin);
                $duracion = (int) $h->duracion_ficha_min;

                for ($m = $minutos; $m + $duracion <= $fin; $m += $duracion) {
                    $inicio = $dia->setTime(intdiv($m, 60), $m % 60);
                    $termina = $inicio->addMinutes($duracion);

                    if ($dia->gte($inicioActual) && $inicio->lte($ahora)) {
                        continue; // sin fichas vencidas desde la semana en curso
                    }
                    $clave = $h->id.'|'.$dia->toDateString().'|'.$inicio->format('H:i');
                    if (isset($existentes[$clave])) {
                        continue;
                    }

                    $nuevas[] = [
                        'horario_atencion_id' => $h->id,
                        'fecha' => $dia->toDateString(),
                        'hora_inicio' => $inicio->format('H:i:s'),
                        'hora_fin' => $termina->format('H:i:s'),
                        'estado' => self::bloqueada($bloqueos, $h, $inicio, $termina) ? 'BLOQUEADA' : 'HABILITADA',
                    ];
                }
            }
        }

        foreach (array_chunk($nuevas, 500) as $lote) {
            DB::table('fichas')->insertOrIgnore($lote);
        }

        return count($nuevas);
    }

    private static function aMinutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora));

        return $h * 60 + $m;
    }

    /**
     * @param array<int,array<string,mixed>> $bloqueos
     */
    private static function bloqueada(array $bloqueos, object $horario, CarbonImmutable $inicio, CarbonImmutable $fin): bool
    {
        foreach ($bloqueos as $b) {
            $aplica = match ($b['alcance']) {
                'CLINICA' => true,
                'CONSULTORIO' => $horario->consultorio_id !== null && (int) $b['consultorio_id'] === (int) $horario->consultorio_id,
                'MEDICO' => (int) $b['medico_id'] === (int) $horario->medico_id,
                default => false,
            };
            if ($aplica && $inicio->lt($b['fin']) && $fin->gt($b['inicio'])) {
                return true;
            }
        }

        return false;
    }
}
