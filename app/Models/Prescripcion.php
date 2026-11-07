<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Prescripcion extends Model
{
    protected $table = "prescripciones";
    public $timestamps = false;

    protected $fillable = [
        "tratamiento_id",
        "medicamento",
        "dosis",
        "frecuencia",
        "duracion_dias",
        "via"
    ];

    // CU32 paso 2: vías de administración que se ofrecen en el formulario.
    public const VIAS = ["Oral", "Sublingual", "Bucal", "Tópica", "Inhalatoria", "Intramuscular", "Intravenosa", "Subcutánea",
        "Oftálmica", "Ótica", "Nasal", "Rectal", "Vaginal"];

    // CU32 paso 2: la lista corta más las vías que ya existan en la base (sin repetir).
    public static function viasDisponibles(): array
    {
        return array_values(array_unique(array_merge(self::VIAS, self::query()->distinct()->orderBy("via")->pluck("via")->all())));
    }

    public function tratamiento()
    {
        return $this->belongsTo(Tratamiento::class, "tratamiento_id");
    }
}
