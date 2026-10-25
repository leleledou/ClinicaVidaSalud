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

    // Valor entero de un parámetro (por ejemplo, edad_mayoria).
    public static function entero(string $clave): int
    {
        return (int) self::findOrFail($clave)->valor;
    }
}
