<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MedicoEspecialidad extends Model
{
    protected $table = "medicos_especialidades";
    public $timestamps = false;

    protected $fillable = [
        "medico_id",
        "especialidad_id",
        "es_principal",
        "estado"
    ];
}
