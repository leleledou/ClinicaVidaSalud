<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParametroLaboratorio extends Model
{
    protected $table = "parametros_laboratorio";
    public $timestamps = false;

    protected $fillable = [
        "tipo_examen_id",
        "nombre",
        "unidad",
        "tipo_valor",
        "orden"
    ];

    // CU33 paso 2: tipos de valor (ck_parametros_tipo_valor) con su texto legible.
    public const TIPOS_VALOR = ["NUMERICO" => "Numérico", "TEXTO" => "Texto"];

    // MIDE: el tipo de examen del parámetro.
    public function tipoExamen()
    {
        return $this->belongsTo(TipoExamen::class, "tipo_examen_id");
    }

    // SE_EVALUA_CON: rangos de referencia (solo los parámetros NUMERICO).
    public function rangos()
    {
        return $this->hasMany(RangoReferencia::class, "parametro_laboratorio_id");
    }

    // MEDIDO_EN: resultados cargados con este parámetro.
    public function resultados()
    {
        return $this->hasMany(ResultadoLaboratorio::class, "parametro_laboratorio_id");
    }

    // CU33 paso 2: con resultados no se cambia su tipo de valor ni su unidad.
    public function tieneResultados(): bool
    {
        return isset($this->resultados_count) ? $this->resultados_count > 0 : $this->resultados()->exists();
    }

    // CU33 paso 2 / ux_parametros_examen_nombre y ux_parametros_examen_orden: nombre (sin mayúsculas ni
    // espacios en los extremos) u orden ya usados en el examen ($idIgnorar: el propio parámetro).
    public static function yaUsadoEnExamen(int $examenId, string $columna, string|int $valor, ?int $idIgnorar = null): bool
    {
        return self::where("tipo_examen_id", $examenId)
            ->when($columna === "nombre",
                fn ($consulta) => $consulta->whereRaw("lower(btrim(nombre)) = lower(btrim(?))", [$valor]),
                fn ($consulta) => $consulta->where("orden", $valor))
            ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
            ->exists();
    }
}
