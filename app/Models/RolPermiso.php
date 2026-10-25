<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolPermiso extends Model
{
    protected $table = "roles_permisos";
    public $incrementing = false;
    public $timestamps = false;

    protected $fillable = [
        "rol_id",
        "permiso_id"
    ];
}
