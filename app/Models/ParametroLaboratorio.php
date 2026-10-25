<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParametroLaboratorio extends Model
{
    protected $table = "parametros_laboratorio";
    public $timestamps = false;

    protected $fillable = [
        "tipo_examen_id",
        "nombre",
        "unidad",
        "tipo_valor",
        "orden"
    ];
}
