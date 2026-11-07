<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    // CU34: prioridades (ck_ordenes_prioridad) y estados (ck_ordenes_estado) con su texto legible.
    public const PRIORIDADES = ["NORMAL" => "Normal", "URGENTE" => "Urgente"];

    public const ESTADOS = [
        "SOLICITADA" => "Solicitada", "MUESTRA_TOMADA" => "Muestra tomada", "RESULTADO_LISTO" => "Resultado listo",
        "REVISADA" => "Revisada", "ANULADA" => "Anulada",
    ];

    protected function casts(): array
    {
        return [
            "fecha_solicitud" => "datetime",
            "fecha_toma_muestra" => "datetime",
            "fecha_revision" => "datetime"
        ];
    }

    /**
     * CU34 paso 2: código siguiente con el formato de los datos sembrados: LAB-<número de la cita>-<n>
     * (LAB-000554-2), n = 1, 2… por cita. Debe llamarse dentro de una transacción: el candado evita que
     * dos solicitudes simultáneas de la misma cita tomen el mismo número.
     */
    public static function siguienteCodigo(Cita $cita): string
    {
        $prefijo = "LAB-" . substr($cita->codigo, 3) . "-";
        DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["ordenes_laboratorio." . $prefijo]);
        $ultimo = self::where("codigo", "like", $prefijo . "%")
            ->pluck("codigo")
            ->map(fn ($codigo) => (int) substr($codigo, strlen($prefijo)))
            ->max() ?? 0;

        return $prefijo . ($ultimo + 1);
    }

    public function textoEstado(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

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
