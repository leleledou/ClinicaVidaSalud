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

    public function especialidad()
    {
        return $this->belongsTo(Especialidad::class, "especialidad_id");
    }

    public function medico()
    {
        return $this->belongsTo(Medico::class, "medico_id");
    }
}
