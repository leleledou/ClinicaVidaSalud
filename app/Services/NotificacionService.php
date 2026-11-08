<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Mail\NotificacionCorreo;
use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Notificacion;
use App\Models\OrdenLaboratorio;
use App\Models\ResultadoLaboratorio;
use App\Models\Usuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * CU37 Enviar recordatorios y notificaciones (RF-38) y CU38 Consultar notificaciones (RF-39).
 * Los avisos los crean otros CU como PENDIENTES (CitaService, LaboratorioService, RegistroController);
 * aquí se entregan: CORREO con el mailer configurado, SISTEMA a la bandeja y PUSH por el navegador.
 */
class NotificacionService
{
    // CU37 paso 1: candidatas por lote y reintentos del correo antes de quedar FALLIDA.
    public const TAMANO_LOTE = 100;
    public const MAX_INTENTOS = 3;

    // CU37 paso 4: avisos que se devuelven al navegador por llamada.
    public const MAX_NUEVAS = 20;

    /**
     * CU37 (RF-38), lo ejecuta el Temporizador cada minuto (notificaciones:enviar). Cada aviso va en su propia
     * transacción con FOR UPDATE SKIP LOCKED (si otra ejecución lo tiene, se salta). Devuelve los contadores.
     */
    public function enviarPendientes(?Carbon $ahora = null): array
    {
        $ahora ??= now();
        $contadores = [
            "CORREO" => ["enviadas" => 0, "reintentar" => 0, "fallidas" => 0],
            "SISTEMA" => ["enviadas" => 0],
            "omitidas" => 0,
        ];

        // CU37 paso 1: candidatas en lotes (por id: las que quedan PENDIENTES para reintentar no se repiten).
        $this->candidatas($ahora)->reorder()->select("notificaciones.id")->chunkById(self::TAMANO_LOTE, function ($lote) use ($ahora, &$contadores) {
            foreach ($lote as $fila) {
                $resultado = DB::transaction(function () use ($fila, $ahora) {
                    // CU37 paso 2: el aviso bloqueado y revisado otra vez (A1: otro proceso lo tomó o ya no está PENDIENTE).
                    $notificacion = Notificacion::whereKey($fila->id)->where("estado", "PENDIENTE")
                        ->lock("for update skip locked")->first();
                    if ($notificacion === null) {
                        return null;
                    }

                    return $notificacion->canal === "CORREO"
                        ? $this->enviarCorreo($notificacion, $ahora)
                        // CU37 paso 3: SISTEMA queda ENVIADA de inmediato (ya está en la bandeja de CU38).
                        : $this->marcarEnviada($notificacion, $ahora);
                });

                if ($resultado === null) {
                    $contadores["omitidas"]++;
                } else {
                    [$canal, $clave] = $resultado;
                    $contadores[$canal][$clave]++;
                }
            }
        });

        // CU37 paso 5: una sola fila de bitácora por ejecución con los contadores (no una por aviso).
        // A2: una ejecución sin candidatas no deja fila (el Temporizador corre cada minuto).
        $procesadas = array_sum($contadores["CORREO"]) + array_sum($contadores["SISTEMA"]) + $contadores["omitidas"];
        if ($procesadas > 0) {
            Bitacora::registrar("ENVIAR_NOTIFICACIONES", "notificaciones", null, true, "notificaciones", null, "SISTEMA",
                null, $contadores + ["ejecutado_en" => $ahora->format("Y-m-d H:i:s")]);
        }

        return $contadores;
    }

    /**
     * CU37 paso 1 (y --simular): PENDIENTES con programada_para <= ahora de los canales CORREO y SISTEMA, las
     * más antiguas primero. El PUSH no lo toca el Temporizador: lo entrega el navegador (paso 4).
     */
    public function candidatas(Carbon $ahora): Builder
    {
        return Notificacion::where("estado", "PENDIENTE")
            ->whereIn("canal", ["CORREO", "SISTEMA"])
            ->where("programada_para", "<=", $ahora)
            ->orderBy("programada_para")
            ->orderBy("id");
    }

    /**
     * CU37 paso 2: correo al de la cuenta de la persona o, si no tiene cuenta (o no tiene correo), al de contacto
     * de la Persona. Éxito → ENVIADA. A3: error → intentos + 1 y sigue PENDIENTE; al MAX_INTENTOS → FALLIDA.
     * A4: sin correo en ningún lado → FALLIDA.
     */
    private function enviarCorreo(Notificacion $notificacion, Carbon $ahora): array
    {
        $persona = $notificacion->persona()->with("usuario")->first();
        $correo = $persona?->usuario?->correo ?: $persona?->correo;
        $intentos = $notificacion->intentos + 1;

        if (! $correo) {
            $notificacion->update(["estado" => "FALLIDA", "intentos" => $intentos]);

            return ["CORREO", "fallidas"];
        }

        try {
            Mail::to($correo)->send(new NotificacionCorreo($notificacion));
        } catch (\Throwable $e) {
            // Solo se registra que falló, sin datos internos.
            Log::warning("No se pudo enviar el correo de la notificación {$notificacion->id}: " . get_class($e));
            $fallida = $intentos >= self::MAX_INTENTOS;
            $notificacion->update(["estado" => $fallida ? "FALLIDA" : "PENDIENTE", "intentos" => $intentos]);

            return ["CORREO", $fallida ? "fallidas" : "reintentar"];
        }

        return $this->marcarEnviada($notificacion, $ahora);
    }

    // CU37 pasos 2 y 3: ENVIADA con la fecha de envío.
    private function marcarEnviada(Notificacion $notificacion, Carbon $ahora): array
    {
        $notificacion->update(["estado" => "ENVIADA", "enviada_en" => $ahora, "intentos" => $notificacion->intentos + 1]);

        return [$notificacion->canal, "enviadas"];
    }

    /**
     * CU37 paso 4 (GET /notificaciones/nuevas): avisos del propio usuario para el navegador. PUSH PENDIENTES
     * (ya vencidos) y avisos ENVIADOS no leídos con id mayor que $desde (el último que vio el navegador). Los
     * PUSH devueltos pasan a ENVIADA. A5: $desde = 0 (primera vez en este navegador): solo los PUSH pendientes,
     * y el navegador empieza a contar desde el último aviso que ya existe (no repite los viejos).
     */
    public function nuevas(Usuario $usuario, int $desde, ?Carbon $ahora = null): array
    {
        $ahora ??= now();

        return DB::transaction(function () use ($usuario, $desde, $ahora) {
            $avisos = Notificacion::bandejaDe($usuario->persona_id, $ahora)
                ->where(fn ($cual) => $cual->where(fn ($push) => $push->where("canal", "PUSH")->where("estado", "PENDIENTE"))
                    ->when($desde > 0, fn ($o) => $o->orWhere(fn ($enviada) => $enviada->where("estado", "ENVIADA")->where("id", ">", $desde))))
                ->orderBy("id")
                ->limit(self::MAX_NUEVAS)
                ->lock("for update skip locked")
                ->get();

            foreach ($avisos->where("estado", "PENDIENTE") as $push) {
                $this->marcarEnviada($push, $ahora);
            }

            $ultimo = $desde > 0
                ? max($desde, (int) $avisos->max("id"))
                : (int) Notificacion::bandejaDe($usuario->persona_id, $ahora)->max("id");

            return [
                "avisos" => $avisos->map(fn ($n) => [
                    "id" => $n->id,
                    "tipo" => $n->textoTipo(),
                    "titulo" => $n->titulo,
                    "mensaje" => $n->mensaje,
                    "critico" => $n->esCritica(),
                ])->values()->all(),
                "ultimo_id" => $ultimo,
                "no_leidas" => Notificacion::bandejaDe($usuario->persona_id, $ahora)->noLeidas()->count(),
            ];
        });
    }

    /**
     * CU38 paso 3: marca como leída una notificación de la bandeja. A1: de otra persona → 403. A2: no está en
     * la bandeja (por ejemplo, un CORREO) → mensaje. A3: ya estaba leída → no cambia nada. Sin bitácora.
     */
    public function marcarLeida(Notificacion $notificacion, Usuario $usuario): bool
    {
        return DB::transaction(function () use ($notificacion, $usuario) {
            $notificacion = Notificacion::whereKey($notificacion->id)->lockForUpdate()->firstOrFail();
            if ((int) $notificacion->persona_id !== (int) $usuario->persona_id) {
                throw new AuthorizationException("Solo puede marcar sus propias notificaciones.");
            }
            if (! Notificacion::bandejaDe($usuario->persona_id)->whereKey($notificacion->id)->exists()) {
                throw new ReglaNegocioException("Esta notificación no está en su bandeja.");
            }
            if ($notificacion->leida()) {
                return false;
            }

            $notificacion->update(["estado" => "LEIDA", "leida_en" => now(), "enviada_en" => $notificacion->enviada_en ?? now()]);

            return true;
        });
    }

    // CU38 paso 3: marca como leídas todas las no leídas de la bandeja del usuario. Devuelve cuántas. Sin bitácora.
    public function marcarTodasLeidas(Usuario $usuario): int
    {
        return DB::transaction(function () use ($usuario) {
            $ahora = now();
            // Un PUSH que se lee en la bandeja antes de llegar al navegador queda con su fecha de entrega.
            Notificacion::bandejaDe($usuario->persona_id, $ahora)->noLeidas()->whereNull("enviada_en")->update(["enviada_en" => $ahora]);

            return Notificacion::bandejaDe($usuario->persona_id, $ahora)->noLeidas()->update(["estado" => "LEIDA", "leida_en" => $ahora]);
        });
    }

    /**
     * CU38 paso 2: enlace al detalle de lo que referencia cada aviso, solo si el usuario puede verlo (la misma
     * regla que la pantalla de destino): cita (Cita::puedeVerla), orden o resultado de laboratorio
     * (OrdenLaboratorio::alAlcanceDe). Devuelve [id del aviso => url o null].
     */
    public function enlaces(iterable $notificaciones, Usuario $usuario): array
    {
        $enlaces = [];
        foreach ($notificaciones as $notificacion) {
            $enlaces[$notificacion->id] = match ($notificacion->referencia_tipo) {
                "citas" => ($cita = Cita::find($notificacion->referencia_id)) && $cita->puedeVerla($usuario)
                    ? route("citas.mostrar", $cita->id) : null,
                "ordenes_laboratorio" => $this->enlaceOrden(OrdenLaboratorio::find($notificacion->referencia_id), $usuario),
                "resultados_laboratorio" => $this->enlaceOrden(ResultadoLaboratorio::find($notificacion->referencia_id)?->orden, $usuario),
                default => null,
            };
        }

        return $enlaces;
    }

    private function enlaceOrden(?OrdenLaboratorio $orden, Usuario $usuario): ?string
    {
        return $orden !== null && $orden->alAlcanceDe($usuario) ? route("ordenes-laboratorio.mostrar", $orden->id) : null;
    }
}
