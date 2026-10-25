<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permiso extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "codigo",
        "nombre",
        "modulo"
    ];

    public function roles()
    {
        return $this->belongsToMany(Rol::class, "roles_permisos", "permiso_id", "rol_id");
    }
}
