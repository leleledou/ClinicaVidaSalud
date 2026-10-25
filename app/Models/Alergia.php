<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alergia extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "historia_clinica_id",
        "tipo",
        "sustancia",
        "reaccion",
        "severidad",
        "activa"
    ];
}
