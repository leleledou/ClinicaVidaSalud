<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Persona extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "tipo_documento",
        "numero_documento",
        "complemento",
        "expedido",
        "nombres",
        "apellido_paterno",
        "apellido_materno",
        "fecha_nacimiento",
        "sexo",
        "telefono",
        "correo",
        "direccion"
    ];
}
