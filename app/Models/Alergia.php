<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alergia extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "historia_clinica_id",
        "tipo",
        "sustancia",
        "reaccion",
        "severidad",
        "activa"
    ];

    // CU29 paso 3: tipos (ck_alergias_tipo) y severidades (ck_alergias_severidad) con su texto legible.
    public const TIPOS = ["MEDICAMENTO" => "Medicamento", "ALIMENTO" => "Alimento", "AMBIENTAL" => "Ambiental", "OTRO" => "Otro"];

    public const SEVERIDADES = ["LEVE" => "Leve", "MODERADA" => "Moderada", "GRAVE" => "Grave"];

    protected function casts(): array
    {
        return [
            "activa" => "boolean"
        ];
    }

    public function historia()
    {
        return $this->belongsTo(HistoriaClinica::class, "historia_clinica_id");
    }

    /**
     * CU29 paso 3: la historia ya tiene otra alergia ACTIVA con la misma sustancia, sin importar
     * tildes, mayúsculas ni espacios (fn_normalizar_texto). $idIgnorar: la propia alergia al modificar.
     */
    public static function hayDuplicadaActiva(int $historiaId, string $sustancia, ?int $idIgnorar = null): bool
    {
        return self::where("historia_clinica_id", $historiaId)
            ->where("activa", true)
            ->whereRaw("fn_normalizar_texto(sustancia) = fn_normalizar_texto(?)", [$sustancia])
            ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
            ->exists();
    }

    public function textoTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }

    public function textoSeveridad(): string
    {
        return self::SEVERIDADES[$this->severidad] ?? $this->severidad;
    }
}
