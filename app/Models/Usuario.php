<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Usuario extends Authenticatable
{
    use Notifiable;

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

    protected $hidden = [
        "password"
    ];

    protected function casts(): array
    {
        return [
            "password" => "hashed",
            "bloqueado_hasta" => "datetime",
            "ultimo_acceso" => "datetime",
            "debe_cambiar_password" => "boolean",
            "suscripcion_push" => "array"
        ];
    }

    // La tabla no tiene remember_token: sin la opción «Recordarme».
    public function getRememberTokenName()
    {
        return "";
    }

    public function rol()
    {
        return $this->belongsTo(Rol::class, "rol_id");
    }

    public function persona()
    {
        return $this->belongsTo(Persona::class, "persona_id");
    }

    // RN12: el permiso se concede solo si el rol está ACTIVO (CU05 A1: un rol inactivo pierde sus permisos).
    public function tienePermiso(string $codigo): bool
    {
        if ($this->rol === null || $this->rol->estado !== "ACTIVO") {
            return false;
        }

        return $this->rol->permisos->contains("codigo", $codigo);
    }
}
