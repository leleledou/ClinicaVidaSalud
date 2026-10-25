<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consulta extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "cita_id",
        "estado",
        "inicio",
        "fin",
        "anamnesis",
        "examen_fisico",
        "peso_kg",
        "talla_cm",
        "presion_sistolica",
        "presion_diastolica",
        "temperatura_c",
        "frecuencia_cardiaca",
        "saturacion_o2",
        "codigo_cie10",
        "diagnostico",
        "indicaciones"
    ];
}
