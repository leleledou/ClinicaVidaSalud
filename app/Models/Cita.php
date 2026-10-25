<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "usuario_registra_id",
        "paciente_id",
        "ficha_id",
        "seguro_paciente_id",
        "administrador_autoriza_id",
        "cita_reemplazada_id",
        "codigo",
        "origen",
        "canal",
        "motivo",
        "estado",
        "codigo_autorizacion_seguro",
        "enlace_virtual",
        "motivo_cancelacion",
        "justificacion_excepcion",
        "fecha_registro"
    ];
}
