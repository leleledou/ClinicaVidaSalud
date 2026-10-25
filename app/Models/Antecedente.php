<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Antecedente extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "historia_clinica_id",
        "tipo",
        "descripcion",
        "fecha_inicio",
        "vigente"
    ];
}
