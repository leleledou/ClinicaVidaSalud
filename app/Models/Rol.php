<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Rol extends Model
{
    protected $table = "roles";
    public $timestamps = false;

    protected $fillable = [
        "nombre",
        "descripcion",
        "estado"
    ];

    public function permisos()
    {
        return $this->belongsToMany(Permiso::class, "roles_permisos", "rol_id", "permiso_id");
    }

    public function usuarios()
    {
        return $this->hasMany(Usuario::class, "rol_id");
    }
}
