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

    // CU31 paso 5: campos clínicos que el médico registra (todos opcionales mientras está EN_CURSO).
    public const CAMPOS_CLINICOS = ["anamnesis", "examen_fisico", "peso_kg", "talla_cm", "presion_sistolica", "presion_diastolica",
        "temperatura_c", "frecuencia_cardiaca", "saturacion_o2", "codigo_cie10", "diagnostico", "indicaciones"];

    // CU31 paso 5: CIE-10 = letra + 2 dígitos + opcional punto y 1 a 4 caracteres (D50.9, Z09.8).
    public const FORMATO_CIE10 = "/^[A-Z][0-9]{2}(\.[A-Z0-9]{1,4})?$/";

    protected function casts(): array
    {
        return [
            "inicio" => "datetime",
            "fin" => "datetime",
            // Como en la base (numeric): «24» y «24.00» son el mismo valor (no cuenta como cambio).
            "peso_kg" => "decimal:2",
            "talla_cm" => "decimal:2",
            "temperatura_c" => "decimal:1"
        ];
    }

    // CU31 paso 1: perfil de médico ACTIVO del usuario con consultas.gestionar (null si no atiende consultas).
    public static function medicoQueAtiende(Usuario $usuario): ?Medico
    {
        $medico = $usuario->persona?->medico;

        return $usuario->tienePermiso("consultas.gestionar") && $medico?->estado === "ACTIVO" ? $medico : null;
    }

    // CU31 paso 7: consultas del médico: EN_CURSO (todas, para continuarlas) y FINALIZADAS de los últimos
    // 30 días; primero las EN_CURSO y luego las más recientes.
    public function scopeMisConsultas($consulta, int $medicoId)
    {
        return $consulta->whereHas("cita.ficha.horario.medicoEspecialidad", fn ($asignacion) => $asignacion->where("medico_id", $medicoId))
            ->where(fn ($estado) => $estado->where("estado", "EN_CURSO")
                ->orWhere(fn ($finalizada) => $finalizada->where("estado", "FINALIZADA")->where("fin", ">=", now()->subDays(30))))
            ->with(["cita.paciente.persona", "cita.ficha.horario.medicoEspecialidad.especialidad"])
            ->orderByRaw("CASE WHEN estado = 'EN_CURSO' THEN 0 ELSE 1 END")
            ->orderByDesc("inicio")
            ->orderByDesc("id");
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
