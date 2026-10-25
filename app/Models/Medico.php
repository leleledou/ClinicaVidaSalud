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

    public function persona()
    {
        return $this->belongsTo(Persona::class, "persona_id");
    }

    public function medicoEspecialidades()
    {
        return $this->hasMany(MedicoEspecialidad::class, "medico_id");
    }

    public function especialidades()
    {
        return $this->belongsToMany(Especialidad::class, "medicos_especialidades", "medico_id", "especialidad_id")
            ->withPivot("id", "es_principal", "estado");
    }
}
