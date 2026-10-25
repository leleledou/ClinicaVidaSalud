<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "codigo",
        "nombre",
        "modulo"
    ];
}
