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
}
