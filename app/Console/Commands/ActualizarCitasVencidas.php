<?php

namespace App\Console\Commands;

use App\Services\AgendaService;
use App\Services\CitaService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * CU27 Actualizar citas vencidas (RF-29). Actor: Temporizador (routes/console.php, cada 5 minutos).
 * Solo llama a CitaService::marcarInasistenciasVencidas e informa; --simular lista las candidatas sin
 * cambiar nada.
 */
class ActualizarCitasVencidas extends Command
{
    protected $signature = "citas:actualizar-vencidas {--simular : Solo lista las citas que se marcarían, sin cambiar nada}";

    protected $description = "CU27: marca como inasistencia las citas vencidas que nadie marcó (RF-29)";

    public function handle(CitaService $citas): int
    {
        $ahora = now();

        // CU27 A1: simulación, solo lectura.
        if ($this->option("simular")) {
            $candidatas = $citas->citasVencidas($ahora)->with("paciente.persona")->get(["citas.*", "fichas.fecha", "fichas.hora_inicio", "fichas.hora_fin"]);

            $this->table(["Código", "Paciente", "Fecha", "Hora", "Estado"], $candidatas->map(fn ($cita) => [
                $cita->codigo,
                $cita->paciente->persona->nombre_completo . " ({$cita->paciente->codigo})",
                AgendaService::DIAS[Carbon::parse($cita->fecha)->dayOfWeekIso] . " " . Carbon::parse($cita->fecha)->format("d/m/Y"),
                substr($cita->hora_inicio, 0, 5) . "–" . substr($cita->hora_fin, 0, 5),
                $cita->estado,
            ])->all());
            $this->info("{$candidatas->count()} citas se marcarían como inasistencia (simulación: no se cambió nada).");

            return self::SUCCESS;
        }

        // CU27 pasos 1 a 4.
        $marcadas = $citas->marcarInasistenciasVencidas($ahora);
        $this->info("{$marcadas} citas marcadas como inasistencia.");

        return self::SUCCESS;
    }
}
