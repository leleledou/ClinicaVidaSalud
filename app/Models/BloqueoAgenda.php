<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BloqueoAgenda extends Model
{
    protected $table = "bloqueos_agenda";
    public $timestamps = false;

    protected $fillable = [
        "alcance",
        "medico_id",
        "consultorio_id",
        "inicio",
        "fin",
        "motivo"
    ];

    // ck_bloqueos_alcance_valido: CLINICA (feriado), CONSULTORIO (cierre) o MEDICO (ausencia).
    public const ALCANCES = ["CLINICA", "CONSULTORIO", "MEDICO"];

    protected function casts(): array
    {
        return [
            "inicio" => "datetime",
            "fin" => "datetime"
        ];
    }

    // AUSENCIA_DE (solo alcance MEDICO).
    public function medico()
    {
        return $this->belongsTo(Medico::class, "medico_id");
    }

    // CIERRA (solo alcance CONSULTORIO).
    public function consultorio()
    {
        return $this->belongsTo(Consultorio::class, "consultorio_id");
    }

    // CU25: «Toda la clínica», el consultorio o el médico (con las relaciones cargadas).
    public function destino(): string
    {
        return match ($this->alcance) {
            "CONSULTORIO" => $this->consultorio?->nombre ?? "—",
            "MEDICO" => $this->medico ? $this->medico->persona->nombre_completo . " ({$this->medico->codigo})" : "—",
            default => "Toda la clínica",
        };
    }
}
