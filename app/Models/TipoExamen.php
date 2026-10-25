<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoExamen extends Model
{
    protected $table = "tipos_examen";
    public $timestamps = false;

    protected $fillable = [
        "codigo",
        "nombre",
        "tipo_muestra",
        "indicaciones_previas",
        "estado"
    ];
}
