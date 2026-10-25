<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResultadoLaboratorio extends Model
{
    protected $table = "resultados_laboratorio";
    public $timestamps = false;

    protected $fillable = [
        "orden_laboratorio_id",
        "parametro_laboratorio_id",
        "rango_referencia_id",
        "usuario_carga_id",
        "valor_numerico",
        "valor_texto",
        "interpretacion",
        "fecha_resultado",
        "observacion"
    ];
}
