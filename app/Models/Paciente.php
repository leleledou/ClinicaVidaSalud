<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paciente extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "recepcionista_registra_id",
        "codigo",
        "estado",
        "fecha_registro"
    ];
}
