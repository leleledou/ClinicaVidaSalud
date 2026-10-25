<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Cita extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "usuario_registra_id",
        "paciente_id",
        "ficha_id",
        "seguro_paciente_id",
        "administrador_autoriza_id",
        "cita_reemplazada_id",
        "codigo",
        "origen",
        "canal",
        "motivo",
        "estado",
        "codigo_autorizacion_seguro",
        "enlace_virtual",
        "motivo_cancelacion",
        "justificacion_excepcion",
        "fecha_registro"
    ];

    // RF06: estados de la cita (ck_citas_estado). Activa = cualquiera distinto de CANCELADA.
    public const ESTADOS = ["PROGRAMADA", "CONFIRMADA", "ATENDIDA", "CANCELADA", "INASISTENCIA"];

    // RN17 / RF08 / RF09: estados vigentes (la cita todavía puede cambiar). Los demás son finales.
    public const ESTADOS_VIGENTES = ["PROGRAMADA", "CONFIRMADA"];

    // RF10: máquina de estados. ATENDIDA, CANCELADA e INASISTENCIA son finales (una cancelada no
    // se marca como atendida; una atendida no se cancela como si no hubiera ocurrido).
    public const TRANSICIONES = [
        "PROGRAMADA" => ["CONFIRMADA", "CANCELADA", "ATENDIDA", "INASISTENCIA"],
        "CONFIRMADA" => ["CANCELADA", "ATENDIDA", "INASISTENCIA"],
        "ATENDIDA" => [],
        "CANCELADA" => [],
        "INASISTENCIA" => [],
    ];

    // Parámetros de configuraciones_sistema leídos una sola vez por petición (la lista los usa en cada fila).
    private static array $parametros = [];

    protected function casts(): array
    {
        return [
            "fecha_registro" => "datetime"
        ];
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, "paciente_id");
    }

    public function ficha()
    {
        return $this->belongsTo(Ficha::class, "ficha_id");
    }

    // REGISTRA: la cuenta que la programó (recepción o el propio paciente).
    public function usuarioRegistra()
    {
        return $this->belongsTo(Usuario::class, "usuario_registra_id");
    }

    // RN20: seguro con el que se atiende (opcional).
    public function seguroPaciente()
    {
        return $this->belongsTo(PacienteSeguro::class, "seguro_paciente_id");
    }

    // RF09: historial de cambios de estado y de ficha.
    public function historiales()
    {
        return $this->hasMany(HistorialCita::class, "cita_id");
    }

    // /ATIENDE (derivado, sin FK): cita → ficha → horario → médico-especialidad → médico.
    public function medico(): ?Medico
    {
        return $this->ficha?->horario?->medicoEspecialidad?->medico;
    }

    // Especialidad derivada por el mismo camino.
    public function especialidad(): ?Especialidad
    {
        return $this->ficha?->horario?->medicoEspecialidad?->especialidad;
    }

    // REEMPLAZA (CU16 A1): la cita cancelada que esta reemplaza.
    public function citaReemplazada()
    {
        return $this->belongsTo(Cita::class, "cita_reemplazada_id");
    }

    // CU16 A1: la cita nueva que reemplaza a esta (cita_reemplazada_id es único).
    public function reemplazadaPor()
    {
        return $this->hasOne(Cita::class, "cita_reemplazada_id");
    }

    // SE_ATIENDE_EN: la consulta de la cita (0..1).
    public function consulta()
    {
        return $this->hasOne(Consulta::class, "cita_id");
    }

    // RF10: la transición del estado actual a $nuevo es válida.
    public function puedePasarA(string $nuevo): bool
    {
        return in_array($nuevo, self::TRANSICIONES[$this->estado] ?? [], true);
    }

    // RN21 / R-HORA: inicio de la cita (el de su ficha).
    public function inicio(): Carbon
    {
        return $this->ficha->inicio();
    }

    // RN21: la cita ya comenzó (o pasó): ya no se confirma, cancela ni reprograma.
    public function comenzo(): bool
    {
        return ! $this->inicio()->isFuture();
    }

    // CU18 A1 / RN21: desde cuándo se registra la inasistencia (inicio + tolerancia_inasistencia_min).
    public function inicioInasistencia(): Carbon
    {
        return $this->inicio()->addMinutes(self::parametro("tolerancia_inasistencia_min"));
    }

    // CU16 A2 / CU17 A1: faltan al menos horas_minimas_cambio_web horas (cambios del paciente por la web).
    public function aTiempoParaLaWeb(): bool
    {
        return now()->addHours(self::parametro("horas_minimas_cambio_web"))->lessThanOrEqualTo($this->inicio());
    }

    // RN18: veces que la cita cambió de ficha (historial con ficha anterior y ficha nueva).
    public function reprogramaciones(): int
    {
        return $this->historiales()->whereNotNull("ficha_anterior_id")->whereNotNull("ficha_nueva_id")->count();
    }

    // RN18: reprogramaciones que le quedan (max_reprogramaciones menos las ya hechas).
    public function reprogramacionesRestantes(): int
    {
        return max(0, self::parametro("max_reprogramaciones") - $this->reprogramaciones());
    }

    // CU16 A2 / CU17 A1 / R-TUTOR: la persona es el paciente o su responsable legal ACTIVO y verificado.
    public function esDeLaPersona(?int $personaId): bool
    {
        if ($personaId === null) {
            return false;
        }
        if ($this->paciente->persona_id === $personaId) {
            return true;
        }

        return VinculoPaciente::where("paciente_id", $this->paciente_id)
            ->where("persona_id", $personaId)
            ->where("estado", "ACTIVO")
            ->where("es_responsable_legal", true)
            ->whereNotNull("fecha_verificacion")
            ->exists();
    }

    // RF06 / CU20: quién ve el detalle de la cita. citas.programar o citas.historial_consultar (el médico
    // solo sus citas, como en CU11 A1), o el paciente dueño / su responsable legal verificado.
    public function puedeVerla(Usuario $usuario): bool
    {
        if ($usuario->tienePermiso("citas.programar")) {
            return true;
        }
        if ($usuario->tienePermiso("citas.historial_consultar")) {
            $medico = $usuario->persona?->medico;

            return $medico === null || $this->medico()?->id === $medico->id;
        }

        return $this->esDeLaPersona($usuario->persona_id);
    }

    // CU18 A2: un médico (sin permisos de recepción) solo registra la atención de SUS citas.
    public static function soloSusCitas(Usuario $usuario): bool
    {
        return ! $usuario->tienePermiso("citas.programar") && $usuario->persona?->medico !== null;
    }

    // CU18 paso 4, A1 y A2 / RN12: el usuario puede marcar ATENDIDA o INASISTENCIA en esta cita.
    public function puedeRegistrarAsistencia(Usuario $usuario): bool
    {
        if (! $usuario->tienePermiso("citas.registrar_asistencia")) {
            return false;
        }

        return ! self::soloSusCitas($usuario) || $this->medico()?->id === $usuario->persona->medico->id;
    }

    /**
     * CU18 paso 2 / RF10: acciones válidas según el estado, la hora actual (RN21) y los permisos del
     * usuario (RN12): confirmar, atender, inasistencia, reprogramar, reasignar y cancelar. Las vistas
     * muestran solo estos botones; CitaService vuelve a validar todo al guardar.
     */
    public function accionesPermitidas(Usuario $usuario): array
    {
        // RF10: ATENDIDA, CANCELADA e INASISTENCIA son finales.
        if (! in_array($this->estado, self::ESTADOS_VIGENTES, true)) {
            return [];
        }

        $acciones = [];
        if (! $this->comenzo()) {
            // RN21: antes de la hora de inicio se confirma, reprograma, reasigna o cancela.
            // CU16 A2 / CU17 A1: el paciente (o su responsable) solo con horas_minimas_cambio_web de anticipación.
            $porLaWeb = $usuario->tienePermiso("citas.autoservicio") && $this->esDeLaPersona($usuario->persona_id) && $this->aTiempoParaLaWeb();

            if ($this->puedePasarA("CONFIRMADA") && $usuario->tienePermiso("citas.confirmar")) {
                $acciones[] = "confirmar";
            }
            // RN18: solo si le quedan reprogramaciones.
            if (($usuario->tienePermiso("citas.reprogramar") || $porLaWeb) && $this->reprogramacionesRestantes() > 0) {
                $acciones[] = "reprogramar";
            }
            // CU16 A1: reasignar a otro médico es solo de recepción.
            if ($usuario->tienePermiso("citas.reprogramar")) {
                $acciones[] = "reasignar";
            }
            if ($usuario->tienePermiso("citas.cancelar") || $porLaWeb) {
                $acciones[] = "cancelar";
            }
        } elseif ($this->puedeRegistrarAsistencia($usuario)) {
            // RN21: ATENDIDA desde la hora de inicio; INASISTENCIA pasada la tolerancia y sin consulta registrada.
            $acciones[] = "atender";
            if (! $this->inicioInasistencia()->isFuture() && ! $this->consulta()->exists()) {
                $acciones[] = "inasistencia";
            }
        }

        return $acciones;
    }

    // Valor entero de configuraciones_sistema (una consulta por clave en cada petición).
    public static function parametro(string $clave): int
    {
        return self::$parametros[$clave] ??= ConfiguracionSistema::entero($clave);
    }
}
