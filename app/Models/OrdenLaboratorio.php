<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrdenLaboratorio extends Model
{
    protected $table = "ordenes_laboratorio";
    public $timestamps = false;

    protected $fillable = [
        "consulta_id",
        "tipo_examen_id",
        "codigo",
        "fecha_solicitud",
        "fecha_toma_muestra",
        "prioridad",
        "estado",
        "laboratorio_procesador",
        "archivo_resultado",
        "fecha_revision"
    ];

    // SOLICITA: la consulta que pidió la orden.
    public function consulta()
    {
        return $this->belongsTo(Consulta::class, "consulta_id");
    }

    // PEDIDO_EN: el tipo de examen.
    public function tipoExamen()
    {
        return $this->belongsTo(TipoExamen::class, "tipo_examen_id");
    }

    // PRODUCE: resultados por parámetro.
    public function resultados()
    {
        return $this->hasMany(ResultadoLaboratorio::class, "orden_laboratorio_id");
    }
}
