<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tratamiento extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "consulta_id",
        "resultado_laboratorio_id",
        "descripcion",
        "fecha_inicio",
        "fecha_fin",
        "estado",
        "respuesta",
        "observacion_evolucion"
    ];
}
