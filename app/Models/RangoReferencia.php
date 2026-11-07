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

    // CU30 paso 3: rango normal en texto («11 – 15,5», «≥ 4», «≤ 200»).
    public function texto(): string
    {
        $numero = fn ($valor) => str_replace(".", ",", rtrim(rtrim((string) $valor, "0"), "."));

        return match (true) {
            $this->valor_min !== null && $this->valor_max !== null => $numero($this->valor_min) . " – " . $numero($this->valor_max),
            $this->valor_min !== null => "≥ " . $numero($this->valor_min),
            $this->valor_max !== null => "≤ " . $numero($this->valor_max),
            default => "—",
        };
    }
}
