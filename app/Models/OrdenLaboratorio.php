<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenLaboratorio extends Model
{
    protected $table = "ordenes_laboratorio";
    public $timestamps = false;

    protected $fillable = [
        "consulta_id",
        "tipo_examen_id",
        "codigo",
        "fecha_solicitud",
        "fecha_toma_muestra",
        "prioridad",
        "estado",
        "laboratorio_procesador",
        "archivo_resultado",
        "fecha_revision"
    ];
}
