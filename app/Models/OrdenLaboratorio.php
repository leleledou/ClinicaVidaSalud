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

    // CU35 paso 1: órdenes que esperan resultados (las únicas en las que se cargan).
    public const PENDIENTES = ["SOLICITADA", "MUESTRA_TOMADA"];

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

    // CU35 paso 1 / CU36 paso 1: color (clase insignia) de cada estado en la lista y la ficha.
    public function claseEstado(): string
    {
        return match ($this->estado) {
            "SOLICITADA" => "insignia-programada",
            "MUESTRA_TOMADA" => "insignia-inasistencia",
            "RESULTADO_LISTO", "REVISADA" => "insignia-activo",
            default => "insignia-cancelada",
        };
    }

    // CU36 paso 3: perfil de médico ACTIVO del usuario con laboratorio.consultar_resultados (null si no lo tiene).
    public static function medicoQueConsulta(Usuario $usuario): ?Medico
    {
        $medico = $usuario->persona?->medico;

        return $usuario->tienePermiso("laboratorio.consultar_resultados") && $medico?->estado === "ACTIVO" ? $medico : null;
    }

    // CU35 paso 1 / CU36 paso 1: perfil de médico ACTIVO del usuario con laboratorio.cargar_resultados o
    // laboratorio.consultar_resultados (null si no lo tiene).
    public static function medicoDeLaboratorio(Usuario $usuario): ?Medico
    {
        $medico = $usuario->persona?->medico;
        $permiso = $usuario->tienePermiso("laboratorio.cargar_resultados") || $usuario->tienePermiso("laboratorio.consultar_resultados");

        return $permiso && $medico?->estado === "ACTIVO" ? $medico : null;
    }

    /**
     * CU35 paso 1 / CU36 paso 1: única regla de qué órdenes ve el usuario (lista, ficha y carga de resultados).
     * "medico": perfil de médico ACTIVO con laboratorio.cargar_resultados o laboratorio.consultar_resultados;
     * SOLO las de pacientes con los que tiene o tuvo cita, aunque pueda cargar (como CU11 A1, CU20 y CU30).
     * "laboratorio": laboratorio.cargar_resultados y NO es médico (la Recepcionista); ve todas.
     * "paciente": resultados.propios; las suyas y las de los menores a su cargo. null: sin acceso (403).
     */
    public static function alcance(Usuario $usuario): ?string
    {
        return match (true) {
            self::medicoDeLaboratorio($usuario) !== null => "medico",
            $usuario->tienePermiso("laboratorio.cargar_resultados") && $usuario->persona?->medico === null => "laboratorio",
            $usuario->tienePermiso("resultados.propios") => "paciente",
            default => null,
        };
    }

    // CU35 paso 1 / CU36 paso 1: órdenes dentro del alcance del usuario (ver alcance()).
    public function scopeVisiblesPara($consulta, Usuario $usuario)
    {
        return match (self::alcance($usuario)) {
            "laboratorio" => $consulta,
            "medico" => $consulta->whereHas("consulta.cita.paciente", fn ($paciente) => $paciente
                ->whereExists(Paciente::citasConMedico(self::medicoDeLaboratorio($usuario)->id))),
            "paciente" => $consulta->whereHas("consulta.cita", fn ($cita) => $cita
                ->whereIn("paciente_id", Paciente::aCargoDe($usuario->persona)->pluck("id"))),
            default => $consulta->whereRaw("1 = 0"),
        };
    }

    // CU35 paso 1 / CU36 paso 1: la orden está al alcance del usuario (misma regla que la lista).
    public function alAlcanceDe(Usuario $usuario): bool
    {
        return self::visiblesPara($usuario)->whereKey($this->id)->exists();
    }

    // CU36 paso 3: el médico que pidió la orden (el de la cita de la consulta).
    public function medicoSolicitante(): ?Medico
    {
        return $this->consulta?->cita?->medico();
    }

    // CU36 paso 3: el usuario es el médico solicitante (perfil ACTIVO con laboratorio.consultar_resultados).
    public function esSolicitante(Usuario $usuario): bool
    {
        $medico = self::medicoQueConsulta($usuario);

        return $medico !== null && $this->medicoSolicitante()?->id === $medico->id;
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
