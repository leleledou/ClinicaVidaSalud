<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Recepcionista extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "codigo",
        "turno",
        "fecha_ingreso",
        "estado"
    ];
}
