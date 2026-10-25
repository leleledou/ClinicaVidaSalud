<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PacienteSeguro extends Model
{
    protected $table = "seguros_paciente";
    public $timestamps = false;

    protected $fillable = [
        "paciente_id",
        "aseguradora_id",
        "recepcionista_verifica_id",
        "numero_poliza",
        "plan",
        "titular",
        "vigente_desde",
        "vigente_hasta",
        "fecha_verificacion",
        "estado"
    ];

    protected function casts(): array
    {
        return [
            "vigente_desde" => "date:Y-m-d",
            "vigente_hasta" => "date:Y-m-d",
            "fecha_verificacion" => "datetime"
        ];
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, "paciente_id");
    }

    public function aseguradora()
    {
        return $this->belongsTo(Aseguradora::class, "aseguradora_id");
    }

    // VERIFICA: recepcionista que revisó la póliza (nulo si está pendiente).
    public function recepcionista()
    {
        return $this->belongsTo(Recepcionista::class, "recepcionista_verifica_id");
    }
}
