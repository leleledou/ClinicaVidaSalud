<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Aseguradora extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "nombre",
        "nit",
        "telefono",
        "estado"
    ];
}
