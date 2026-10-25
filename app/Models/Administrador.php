<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Administrador extends Model
{
    protected $table = "administradores";
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "codigo",
        "cargo",
        "estado"
    ];
}
