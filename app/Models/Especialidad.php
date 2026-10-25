<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Especialidad extends Model
{
    protected $table = "especialidades";
    public $timestamps = false;

    protected $fillable = [
        "nombre",
        "descripcion",
        "edad_minima",
        "edad_maxima",
        "sexo_permitido",
        "duracion_sugerida_min",
        "estado"
    ];

    public function medicoEspecialidades()
    {
        return $this->hasMany(MedicoEspecialidad::class);
    }

    public function medicos()
    {
        return $this->belongsToMany(Medico::class, "medicos_especialidades", "especialidad_id", "medico_id")
            ->withPivot("id", "es_principal", "estado");
    }
}
