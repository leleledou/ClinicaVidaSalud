<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bitacora extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "usuario_id",
        "accion",
        "modulo",
        "tabla_afectada",
        "registro_id",
        "canal",
        "transcripcion_voz",
        "datos_anteriores",
        "datos_nuevos",
        "exito",
        "ip",
        "fecha_hora"
    ];

    protected function casts(): array
    {
        return [
            "datos_anteriores" => "array",
            "datos_nuevos" => "array"
        ];
    }

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, "usuario_id");
    }

    // Registra una acción en la bitácora. Nunca se guardan contraseñas.
    // fecha_hora la pone la base (useCurrent). Los datos anteriores y nuevos son opcionales.
    public static function registrar(string $accion, string $modulo, ?int $usuarioId, bool $exito = true, ?string $tablaAfectada = null, ?int $registroId = null, string $canal = "WEB", ?array $datosAnteriores = null, ?array $datosNuevos = null): self
    {
        return self::create([
            "usuario_id" => $usuarioId,
            "accion" => $accion,
            "modulo" => $modulo,
            "tabla_afectada" => $tablaAfectada,
            "registro_id" => $registroId,
            "canal" => $canal,
            "datos_anteriores" => $datosAnteriores,
            "datos_nuevos" => $datosNuevos,
            "exito" => $exito,
            "ip" => request()->ip()
        ]);
    }
}
