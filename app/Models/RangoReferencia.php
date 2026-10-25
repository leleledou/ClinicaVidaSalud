<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RangoReferencia extends Model
{
    protected $table = "rangos_referencia";
    public $timestamps = false;

    protected $fillable = [
        "parametro_laboratorio_id",
        "edad_min_meses",
        "edad_max_meses",
        "sexo",
        "valor_min",
        "valor_max",
        "critico_min",
        "critico_max",
        "vigente_desde",
        "vigente_hasta"
    ];
}
