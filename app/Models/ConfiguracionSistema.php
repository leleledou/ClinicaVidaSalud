<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfiguracionSistema extends Model
{
    protected $table = "configuraciones_sistema";
    protected $primaryKey = "clave";
    public $incrementing = false;
    protected $keyType = "string";
    public $timestamps = false;

    protected $fillable = [
        "clave",
        "valor",
        "tipo_dato",
        "descripcion"
    ];
}
