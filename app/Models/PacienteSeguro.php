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
}
