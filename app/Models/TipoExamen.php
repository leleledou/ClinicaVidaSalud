<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoExamen extends Model
{
    protected $table = "tipos_examen";
    public $timestamps = false;

    protected $fillable = [
        "codigo",
        "nombre",
        "tipo_muestra",
        "indicaciones_previas",
        "estado"
    ];

    // CU33 paso 1: código en mayúsculas, sin espacios (letras, dígitos y guion bajo, como HEMOGRAMA o PERFIL_LIPIDICO).
    public const FORMATO_CODIGO = "/^[A-Z0-9_]{2,30}$/";

    // MIDE: parámetros del examen, en su orden.
    public function parametros()
    {
        return $this->hasMany(ParametroLaboratorio::class, "tipo_examen_id")->orderBy("orden");
    }

    // CU34 paso 1: exámenes que se pueden pedir: ACTIVOS y con al menos un parámetro.
    public function scopeSolicitables($consulta)
    {
        return $consulta->where("estado", "ACTIVO")->whereHas("parametros")->orderBy("nombre");
    }

    // CU33 paso 1 / ux_tipos_examen_nombre: nombre ya usado sin importar mayúsculas ni espacios ($idIgnorar: el propio examen).
    public static function nombreUsado(string $nombre, ?int $idIgnorar = null): bool
    {
        return self::whereRaw("lower(regexp_replace(btrim(nombre), '\\s+', ' ', 'g')) = lower(?)", [$nombre])
            ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
            ->exists();
    }
}
