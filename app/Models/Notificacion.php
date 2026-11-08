<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Notificacion extends Model
{
    protected $table = "notificaciones";
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "tipo",
        "canal",
        "titulo",
        "mensaje",
        "referencia_tipo",
        "referencia_id",
        "programada_para",
        "enviada_en",
        "leida_en",
        "estado",
        "intentos"
    ];

    // CU38 paso 2: texto de cada tipo (ck_notificaciones_tipo).
    public const TIPOS = [
        "RECORDATORIO_CITA" => "Recordatorio de cita", "CITA_PROGRAMADA" => "Cita programada",
        "CITA_REPROGRAMADA" => "Cita reprogramada", "CITA_CANCELADA" => "Cita cancelada",
        "RESULTADO_LISTO" => "Resultados listos", "VALOR_CRITICO" => "Valor crítico", "VERIFICAR_CORREO" => "Verificar correo",
    ];

    // CU38 paso 2: filtro de la bandeja.
    public const FILTROS = ["TODAS" => "Todas", "NO_LEIDAS" => "No leídas"];

    // CU37 paso 1: canales que se ven en la bandeja (el CORREO llega al buzón de la persona).
    public const CANALES_BANDEJA = ["SISTEMA", "PUSH"];

    protected function casts(): array
    {
        return [
            "programada_para" => "datetime",
            "enviada_en" => "datetime",
            "leida_en" => "datetime"
        ];
    }

    /**
     * CU38 paso 2: bandeja de la persona: canal SISTEMA o PUSH, programada_para <= ahora, sin CANCELADA y sin
     * PENDIENTE salvo PUSH (el PUSH pendiente lo entrega el navegador, CU37 paso 4).
     */
    public function scopeBandejaDe($consulta, int $personaId, ?Carbon $ahora = null)
    {
        return $consulta->where("persona_id", $personaId)
            ->whereIn("canal", self::CANALES_BANDEJA)
            ->where("programada_para", "<=", $ahora ?? now())
            ->where("estado", "<>", "CANCELADA")
            ->where(fn ($estado) => $estado->where("estado", "<>", "PENDIENTE")->orWhere("canal", "PUSH"));
    }

    // CU38 pasos 2 y 4: los avisos de la bandeja que todavía no se leyeron.
    public function scopeNoLeidas($consulta)
    {
        return $consulta->where("estado", "<>", "LEIDA");
    }

    // CU38 paso 4: número de no leídas del usuario (una sola consulta de conteo por petición).
    public static function contarNoLeidas(Usuario $usuario): int
    {
        $personaId = $usuario->persona_id;

        return once(fn () => self::bandejaDe($personaId)->noLeidas()->count());
    }

    public function textoTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function esCritica(): bool
    {
        return $this->tipo === "VALOR_CRITICO";
    }

    public function leida(): bool
    {
        return $this->estado === "LEIDA";
    }

    // RECIBE: la persona destinataria (tenga o no cuenta).
    public function persona()
    {
        return $this->belongsTo(Persona::class, "persona_id");
    }
}
