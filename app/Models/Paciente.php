<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Paciente extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "recepcionista_registra_id",
        "codigo",
        "estado",
        "fecha_registro"
    ];

    protected function casts(): array
    {
        return [
            "fecha_registro" => "datetime"
        ];
    }

    public function persona()
    {
        return $this->belongsTo(Persona::class, "persona_id");
    }

    // REGISTRA: nulo si el paciente se autorregistró.
    public function recepcionista()
    {
        return $this->belongsTo(Recepcionista::class, "recepcionista_registra_id");
    }

    public function historiaClinica()
    {
        return $this->hasOne(HistoriaClinica::class, "paciente_id");
    }

    public function citas()
    {
        return $this->hasMany(Cita::class, "paciente_id");
    }

    // CU13: contactos de emergencia y responsables legales.
    public function vinculos()
    {
        return $this->hasMany(VinculoPaciente::class, "paciente_id");
    }

    // RN20: seguros médicos del paciente.
    public function seguros()
    {
        return $this->hasMany(PacienteSeguro::class, "paciente_id");
    }

    // CU11 pasos 2-3 (y CU15 paso 1): pacientes por documento, código PAC- o parte del nombre o
    // apellido, con parámetros. El documento se normaliza en los dos lados y los nombres con
    // fn_normalizar_texto (sin tildes ni mayúsculas). Sin texto: los más recientes primero.
    public static function buscar(string $texto): Builder
    {
        $consulta = self::with("persona")
            ->join("personas", "personas.id", "=", "pacientes.persona_id")
            ->select("pacientes.*");

        if ($texto === "") {
            return $consulta->orderByDesc("pacientes.fecha_registro")->orderByDesc("pacientes.id");
        }

        // Escapa los comodines de LIKE que escriba el usuario.
        $escapar = fn (string $valor) => str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $valor);
        $documento = Persona::normalizarDocumento($texto);
        $patron = "%" . $escapar($texto) . "%";

        return $consulta->where(function ($condicion) use ($patron, $documento, $escapar) {
            $condicion->whereRaw("upper(pacientes.codigo) LIKE upper(?)", [$patron])
                ->orWhereRaw("fn_normalizar_texto(personas.nombres || ' ' || personas.apellido_paterno || ' ' || coalesce(personas.apellido_materno, '')) LIKE fn_normalizar_texto(?)", [$patron]);
            if ($documento !== null) {
                $condicion->orWhereRaw("regexp_replace(upper(personas.numero_documento), '[\\s.\\-]', '', 'g') LIKE ?", ["%" . $escapar($documento) . "%"]);
            }
        })
            ->orderBy("personas.apellido_paterno")
            ->orderBy("personas.nombres");
    }

    // CU10 paso 7 / CU12 paso 6: registra el perfil de paciente con su código PAC- y su historia
    // clínica abierta hoy. $recepcionistaId = null es autorregistro. Debe llamarse dentro de una
    // transacción (el candado del código dura hasta el final de la transacción).
    public static function registrar(Persona $persona, ?int $recepcionistaId): self
    {
        $paciente = self::create([
            "persona_id" => $persona->id,
            "recepcionista_registra_id" => $recepcionistaId,
            "codigo" => self::siguienteCodigo(),
            "estado" => "ACTIVO",
        ]);

        HistoriaClinica::create([
            "paciente_id" => $paciente->id,
            "fecha_apertura" => today()->toDateString(),
            "grupo_sanguineo" => "DESCONOCIDO",
        ]);

        return $paciente;
    }

    // CU10 paso 7: código PAC-NNNNNN siguiente. El candado de la transacción evita que dos
    // registros simultáneos tomen el mismo número.
    public static function siguienteCodigo(): string
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["pacientes.codigo"]);
        $ultimo = DB::selectOne('SELECT coalesce(max(substring(codigo from 5)::int), 0) AS n FROM pacientes WHERE codigo ~ \'^PAC-[0-9]+$\'')->n;

        return sprintf("PAC-%06d", $ultimo + 1);
    }

    // CU13 / CU15: true si en esa fecha (hoy por defecto) el paciente no llega a la edad de mayoría.
    public function esMenor(?Carbon $fecha = null): bool
    {
        return $this->persona->edadEn($fecha ?? today()) < ConfiguracionSistema::entero("edad_mayoria");
    }

    // CU13 / RN16: tiene al menos un responsable legal ACTIVO y verificado.
    public function tieneResponsableVerificado(): bool
    {
        return $this->vinculos()
            ->where("estado", "ACTIVO")
            ->where("es_responsable_legal", true)
            ->whereNotNull("fecha_verificacion")
            ->exists();
    }
}
