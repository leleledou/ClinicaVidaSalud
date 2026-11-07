<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tratamiento extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "consulta_id",
        "resultado_laboratorio_id",
        "descripcion",
        "fecha_inicio",
        "fecha_fin",
        "estado",
        "respuesta",
        "observacion_evolucion"
    ];

    // CU30 paso 3: estado (ck_tratamientos_estado) y respuesta (ck_tratamientos_respuesta) con su texto legible.
    public const ESTADOS = ["EN_CURSO" => "En curso", "FINALIZADO" => "Finalizado", "SUSPENDIDO" => "Suspendido"];

    public const RESPUESTAS = ["PENDIENTE" => "Pendiente", "MEJORO" => "Mejoró", "SIN_CAMBIO" => "Sin cambio", "EMPEORO" => "Empeoró"];

    protected function casts(): array
    {
        return [
            "fecha_inicio" => "date:Y-m-d",
            "fecha_fin" => "date:Y-m-d"
        ];
    }

    public function consulta()
    {
        return $this->belongsTo(Consulta::class, "consulta_id");
    }

    // ORIGINA: el resultado de laboratorio que lo motivó (0..1, del mismo paciente).
    public function resultadoLaboratorio()
    {
        return $this->belongsTo(ResultadoLaboratorio::class, "resultado_laboratorio_id");
    }

    // PRESCRIBE: medicamentos del tratamiento.
    public function prescripciones()
    {
        return $this->hasMany(Prescripcion::class, "tratamiento_id");
    }
}
