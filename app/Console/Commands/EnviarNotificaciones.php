<?php

namespace App\Console\Commands;

use App\Models\Notificacion;
use App\Services\NotificacionService;
use Illuminate\Console\Command;

/**
 * CU37 Enviar recordatorios y notificaciones (RF-38). Actor: Temporizador (routes/console.php, cada minuto).
 * Solo llama a NotificacionService::enviarPendientes e informa los contadores; --simular lista las candidatas
 * sin cambiar nada.
 */
class EnviarNotificaciones extends Command
{
    protected $signature = "notificaciones:enviar {--simular : Solo lista las notificaciones que se enviarían, sin cambiar nada}";

    protected $description = "CU37: envía los avisos pendientes por correo y por el sistema (RF-38)";

    public function handle(NotificacionService $notificaciones): int
    {
        $ahora = now();

        // CU37 paso 1, simulación: solo lectura.
        if ($this->option("simular")) {
            $candidatas = $notificaciones->candidatas($ahora)->with("persona.usuario")->get();

            $this->table(["Id", "Tipo", "Canal", "Para", "Destino", "Programada", "Intentos"], $candidatas->map(fn (Notificacion $n) => [
                $n->id,
                $n->tipo,
                $n->canal,
                $n->persona->nombre_completo,
                $n->canal === "CORREO" ? ($n->persona->usuario?->correo ?: $n->persona->correo ?: "(sin correo: quedaría FALLIDA)") : "bandeja",
                $n->programada_para->format("d/m/Y H:i"),
                $n->intentos,
            ])->all());
            $this->info("{$candidatas->count()} notificaciones se enviarían (simulación: no se cambió nada).");

            return self::SUCCESS;
        }

        // CU37 pasos 1 a 5.
        $c = $notificaciones->enviarPendientes($ahora);
        $this->table(["Canal", "Enviadas", "Para reintentar", "Fallidas"], [
            ["CORREO", $c["CORREO"]["enviadas"], $c["CORREO"]["reintentar"], $c["CORREO"]["fallidas"]],
            ["SISTEMA", $c["SISTEMA"]["enviadas"], 0, 0],
        ]);
        $this->info("Omitidas (las tenía otro proceso o ya no estaban pendientes): {$c['omitidas']}.");

        return self::SUCCESS;
    }
}
