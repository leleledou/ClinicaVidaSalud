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

    // RN15 / R-EDAD: null si la especialidad atiende a esa edad y sexo; si no, el mensaje en español.
    public function restriccionPara(int $edad, string $sexo): ?string
    {
        $minima = $this->edad_minima;
        $maxima = $this->edad_maxima;

        if (($minima !== null && $edad < $minima) || ($maxima !== null && $edad > $maxima)) {
            $rango = match (true) {
                $minima !== null && $maxima !== null => "de {$minima} a {$maxima} años",
                $minima !== null => "desde los {$minima} años",
                default => "hasta los {$maxima} años",
            };

            return "{$this->nombre} atiende {$rango}; el paciente tendrá {$edad} años en la fecha de la cita.";
        }

        if ($this->sexo_permitido !== null && $this->sexo_permitido !== $sexo) {
            return "{$this->nombre} atiende solo a " . ($this->sexo_permitido === "F" ? "mujeres" : "hombres") . ".";
        }

        return null;
    }

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
