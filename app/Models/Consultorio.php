<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consultorio extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "nombre",
        "ubicacion",
        "estado"
    ];
}
