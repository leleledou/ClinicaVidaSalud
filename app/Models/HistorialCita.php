<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialCita extends Model
{
    protected $table = "historiales_cita";
    public $timestamps = false;

    protected $fillable = [
        "cita_id",
        "usuario_realiza_id",
        "ficha_anterior_id",
        "ficha_nueva_id",
        "estado_anterior",
        "estado_nuevo",
        "canal",
        "motivo",
        "fecha_hora"
    ];
}
