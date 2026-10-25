<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Usuario extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "rol_id",
        "nombre_usuario",
        "correo",
        "password",
        "estado",
        "debe_cambiar_password",
        "intentos_fallidos",
        "bloqueado_hasta",
        "correo_verificado_en",
        "ultimo_acceso",
        "notificar_por_correo",
        "notificar_por_push",
        "horas_anticipacion",
        "suscripcion_push"
    ];
}
