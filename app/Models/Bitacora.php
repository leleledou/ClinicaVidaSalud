<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bitacora extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "usuario_id",
        "accion",
        "modulo",
        "tabla_afectada",
        "registro_id",
        "canal",
        "transcripcion_voz",
        "datos_anteriores",
        "datos_nuevos",
        "exito",
        "ip",
        "fecha_hora"
    ];
}
