<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VinculoPaciente extends Model
{
    protected $table = "vinculos_paciente";
    public $timestamps = false;

    protected $fillable = [
        "paciente_id",
        "persona_id",
        "recepcionista_verifica_id",
        "parentesco",
        "es_contacto_emergencia",
        "es_responsable_legal",
        "fecha_verificacion",
        "estado"
    ];
}
