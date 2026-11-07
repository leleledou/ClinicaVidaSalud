<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Antecedente extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "historia_clinica_id",
        "tipo",
        "descripcion",
        "fecha_inicio",
        "vigente"
    ];

    // CU29 paso 2: tipos de ck_antecedentes_tipo con su texto legible (MEDICACION_ACTUAL es la medicación habitual, RF-30).
    public const TIPOS = [
        "PATOLOGICO" => "Patológico",
        "CRONICO" => "Enfermedad crónica",
        "QUIRURGICO" => "Quirúrgico",
        "FAMILIAR" => "Familiar",
        "GINECO_OBSTETRICO" => "Gineco-obstétrico",
        "HABITO" => "Hábito",
        "MEDICACION_ACTUAL" => "Medicación habitual",
    ];

    protected function casts(): array
    {
        return [
            "fecha_inicio" => "date:Y-m-d",
            "vigente" => "boolean"
        ];
    }

    public function historia()
    {
        return $this->belongsTo(HistoriaClinica::class, "historia_clinica_id");
    }

    // CU29 paso 2: tipos que admite un paciente (GINECO_OBSTETRICO solo si es mujer).
    public static function tiposPara(string $sexo): array
    {
        return $sexo === "F" ? self::TIPOS : array_diff_key(self::TIPOS, ["GINECO_OBSTETRICO" => true]);
    }

    public function textoTipo(): string
    {
        return self::TIPOS[$this->tipo] ?? $this->tipo;
    }
}
