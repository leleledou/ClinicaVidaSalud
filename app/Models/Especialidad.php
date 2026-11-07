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
        return $this->restriccionEdad($edad) ?? $this->restriccionSexo($sexo);
    }

    // RN15 (edad): null si la edad está en el rango de la especialidad. CU26 la exceptúa con autorización.
    public function restriccionEdad(int $edad): ?string
    {
        $minima = $this->edad_minima;
        $maxima = $this->edad_maxima;

        if (($minima !== null && $edad < $minima) || ($maxima !== null && $edad > $maxima)) {
            return "{$this->nombre} atiende {$this->textoRangoEdad()}; el paciente tendrá {$edad} años en la fecha de la cita.";
        }

        return null;
    }

    // RN15 (sexo): null si la especialidad admite ese sexo. CU26 no lo exceptúa.
    public function restriccionSexo(string $sexo): ?string
    {
        if ($this->sexo_permitido !== null && $this->sexo_permitido !== $sexo) {
            return "{$this->nombre} atiende solo a " . ($this->sexo_permitido === "F" ? "mujeres" : "hombres") . ".";
        }

        return null;
    }

    // RN15: rango de edad en texto («de 0 a 15 años», «desde los 18 años», «todas las edades»).
    public function textoRangoEdad(): string
    {
        $minima = $this->edad_minima;
        $maxima = $this->edad_maxima;

        return match (true) {
            $minima !== null && $maxima !== null => "de {$minima} a {$maxima} años",
            $minima !== null => "desde los {$minima} años",
            $maxima !== null => "hasta los {$maxima} años",
            default => "todas las edades",
        };
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
