<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HorarioAtencion extends Model
{
    protected $table = "horarios_atencion";
    public $timestamps = false;

    protected $fillable = [
        "medico_especialidad_id",
        "consultorio_id",
        "dia_semana",
        "hora_inicio",
        "hora_fin",
        "duracion_ficha_min",
        "modalidad",
        "vigente_desde",
        "vigente_hasta",
        "estado"
    ];

    public function consultorio()
    {
        return $this->belongsTo(Consultorio::class, "consultorio_id");
    }

    public function medicoEspecialidad()
    {
        return $this->belongsTo(MedicoEspecialidad::class, "medico_especialidad_id");
    }
}
