<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Consulta extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "cita_id",
        "estado",
        "inicio",
        "fin",
        "anamnesis",
        "examen_fisico",
        "peso_kg",
        "talla_cm",
        "presion_sistolica",
        "presion_diastolica",
        "temperatura_c",
        "frecuencia_cardiaca",
        "saturacion_o2",
        "codigo_cie10",
        "diagnostico",
        "indicaciones"
    ];

    protected function casts(): array
    {
        return [
            "inicio" => "datetime",
            "fin" => "datetime"
        ];
    }

    // SE_ATIENDE_EN: la cita de la consulta.
    public function cita()
    {
        return $this->belongsTo(Cita::class, "cita_id");
    }

    // INDICA: tratamientos de la consulta.
    public function tratamientos()
    {
        return $this->hasMany(Tratamiento::class, "consulta_id");
    }

    // SOLICITA: órdenes de laboratorio de la consulta.
    public function ordenes()
    {
        return $this->hasMany(OrdenLaboratorio::class, "consulta_id");
    }

    // CU30 paso 3: signos vitales registrados, en texto («Peso 24 kg · PA 100/60 mmHg · …»).
    public function textoSignosVitales(): string
    {
        $signos = array_filter([
            $this->peso_kg !== null ? "Peso " . (float) $this->peso_kg . " kg" : null,
            $this->talla_cm !== null ? "Talla " . (float) $this->talla_cm . " cm" : null,
            $this->presion_sistolica !== null ? "PA {$this->presion_sistolica}/{$this->presion_diastolica} mmHg" : null,
            $this->temperatura_c !== null ? "T " . (float) $this->temperatura_c . " °C" : null,
            $this->frecuencia_cardiaca !== null ? "FC {$this->frecuencia_cardiaca} lpm" : null,
            $this->saturacion_o2 !== null ? "SatO₂ {$this->saturacion_o2} %" : null,
        ]);

        return $signos ? implode(" · ", $signos) : "—";
    }
}
