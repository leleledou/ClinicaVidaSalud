<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Medico extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "codigo",
        "registro_profesional",
        "biografia",
        "estado"
    ];
}
