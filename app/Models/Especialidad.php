<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Especialidad extends Model
{
    protected $table = "especialidades";
    public $timestamps = false;

    protected $fillable = [
        "nombre",
        "descripcion",
        "edad_minima",
        "edad_maxima",
        "sexo_permitido",
        "duracion_sugerida_min",
        "estado"
    ];
}
