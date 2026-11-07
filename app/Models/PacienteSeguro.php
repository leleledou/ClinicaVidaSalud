<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class PacienteSeguro extends Model
{
    protected $table = "seguros_paciente";
    public $timestamps = false;

    protected $fillable = [
        "paciente_id",
        "aseguradora_id",
        "recepcionista_verifica_id",
        "numero_poliza",
        "plan",
        "titular",
        "vigente_desde",
        "vigente_hasta",
        "fecha_verificacion",
        "estado"
    ];

    protected function casts(): array
    {
        return [
            "vigente_desde" => "date:Y-m-d",
            "vigente_hasta" => "date:Y-m-d",
            "fecha_verificacion" => "datetime"
        ];
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, "paciente_id");
    }

    public function aseguradora()
    {
        return $this->belongsTo(Aseguradora::class, "aseguradora_id");
    }

    // VERIFICA: recepcionista que revisó la póliza (nulo si está pendiente).
    public function recepcionista()
    {
        return $this->belongsTo(Recepcionista::class, "recepcionista_verifica_id");
    }

    // CUBRE: citas que usan esta póliza (citas.seguro_paciente_id).
    public function citas()
    {
        return $this->hasMany(Cita::class, "seguro_paciente_id");
    }

    // CU24 paso 2: verificada = tiene recepcionista y fecha (ck_seguros_verificacion: siempre juntos).
    public function verificada(): bool
    {
        return $this->fecha_verificacion !== null;
    }

    // CU24 paso 2: situación de la vigencia en una fecha (hoy por defecto): Vigente, Vencida o Aún no vigente.
    public function situacion(?Carbon $fecha = null): string
    {
        $dia = ($fecha ?? today())->toDateString();

        return match (true) {
            $dia < $this->vigente_desde->toDateString() => "Aún no vigente",
            $dia > $this->vigente_hasta->toDateString() => "Vencida",
            default => "Vigente",
        };
    }

    // CU24 paso 2: activas primero; dentro de cada grupo, la vigencia más reciente primero.
    public function scopeActivasPrimero(Builder $consulta): Builder
    {
        return $consulta->orderByRaw("CASE WHEN estado = 'ACTIVO' THEN 0 ELSE 1 END")
            ->orderByDesc("vigente_hasta")
            ->orderBy("id");
    }

    /**
     * CU24 paso 3 / A3: el paciente ya tiene otra póliza ACTIVA con la misma aseguradora y el mismo
     * número (sin espacios en los extremos ni mayúsculas). Otro paciente sí puede tener el mismo
     * número (familia: para eso está el titular). $idIgnorar: la propia póliza al modificar o reactivar.
     */
    public static function hayDuplicadoActivo(int $pacienteId, int $aseguradoraId, string $numero, ?int $idIgnorar = null): bool
    {
        return self::where("paciente_id", $pacienteId)
            ->where("aseguradora_id", $aseguradoraId)
            ->where("estado", "ACTIVO")
            ->whereRaw("lower(btrim(numero_poliza)) = lower(btrim(?))", [$numero])
            ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
            ->exists();
    }

    /**
     * CU24 paso 7: citas PROGRAMADAS o CONFIRMADAS que todavía no empezaron y usan esta póliza. Con
     * $desde/$hasta, solo las que quedarían fuera de esa vigencia (al modificarla).
     */
    public function citasFuturas(?string $desde = null, ?string $hasta = null): int
    {
        return $this->citas()
            ->join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->whereIn("citas.estado", Cita::ESTADOS_VIGENTES)
            ->whereRaw("(fichas.fecha + fichas.hora_inicio) > ?::timestamp", [now()->format("Y-m-d H:i:s")])
            ->when($desde !== null, fn ($consulta) => $consulta->where(fn ($fuera) => $fuera
                ->where("fichas.fecha", "<", $desde)
                ->orWhere("fichas.fecha", ">", $hasta)))
            ->count();
    }
}
