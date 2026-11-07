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

    // CU30 paso 3: interpretación (ck_resultados_interpretacion) con su texto y su color (clase insignia).
    public const INTERPRETACIONES = [
        "NORMAL" => ["Normal", "insignia-activo"],
        "BAJO" => ["Bajo", "insignia-inasistencia"],
        "ALTO" => ["Alto", "insignia-inasistencia"],
        "CRITICO_BAJO" => ["Crítico bajo", "insignia-cancelada"],
        "CRITICO_ALTO" => ["Crítico alto", "insignia-cancelada"],
        "NO_APLICA" => ["No aplica", "insignia-inactivo"],
    ];

    protected function casts(): array
    {
        return [
            "fecha_resultado" => "datetime"
        ];
    }

    // PRODUCE: la orden del resultado.
    public function orden()
    {
        return $this->belongsTo(OrdenLaboratorio::class, "orden_laboratorio_id");
    }

    // MEDIDO_EN: el parámetro medido.
    public function parametro()
    {
        return $this->belongsTo(ParametroLaboratorio::class, "parametro_laboratorio_id");
    }

    // APLICADO_A: el rango con el que se interpretó (se conserva aunque luego cambien los rangos).
    public function rango()
    {
        return $this->belongsTo(RangoReferencia::class, "rango_referencia_id");
    }

    // CU30 paso 3: valor con su unidad (numérico sin ceros sobrantes, o el texto).
    public function textoValor(): string
    {
        if ($this->valor_numerico === null) {
            return (string) $this->valor_texto;
        }

        return str_replace(".", ",", rtrim(rtrim((string) $this->valor_numerico, "0"), "."))
            . ($this->parametro?->unidad ? " " . $this->parametro->unidad : "");
    }

    public function textoInterpretacion(): string
    {
        return self::INTERPRETACIONES[$this->interpretacion][0] ?? $this->interpretacion;
    }

    public function claseInterpretacion(): string
    {
        return self::INTERPRETACIONES[$this->interpretacion][1] ?? "insignia-inactivo";
    }
}
