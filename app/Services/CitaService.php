<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\ConfiguracionSistema;
use App\Models\Ficha;
use App\Models\HistorialCita;
use App\Models\Notificacion;
use App\Models\Paciente;
use App\Models\PacienteSeguro;
use App\Models\Persona;
use App\Models\Usuario;
use App\Models\VinculoPaciente;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de las citas (RF06–RF10, RN03–RN10, RN12, RN13, RN15–RN21). La usan CU15 Programar
 * cita, CU16 Reprogramar, CU17 Cancelar y CU18 Registrar asistencia. Toda escritura va en UNA
 * transacción; las reglas que no se cumplen lanzan ReglaNegocioException con el mensaje en español
 * y la falta de permiso lanza AuthorizationException (403).
 */
class CitaService
{
    public const ORIGEN_RECEPCION = "RECEPCION";
    public const ORIGEN_AUTOSERVICIO = "AUTOSERVICIO";

    // Relaciones de la ficha que usan las validaciones, los avisos y la bitácora.
    private const RELACIONES_FICHA = ["horario.consultorio", "horario.medicoEspecialidad.medico.persona", "horario.medicoEspecialidad.especialidad"];

    /**
     * CU15 pasos 6 a 9: programa una cita.
     * $datos: paciente_id, ficha_id, motivo, seguro_paciente_id y codigo_autorizacion_seguro (opcionales).
     * $origen: RECEPCION o AUTOSERVICIO (CU15 A1).
     */
    public function programar(array $datos, Usuario $usuario, string $origen): Cita
    {
        // RN13 / RF06: la disponibilidad se comprueba otra vez dentro de la misma transacción que guarda.
        return $this->enTransaccion(function () use ($datos, $usuario, $origen) {
            $cita = $this->registrarCita($datos, $usuario, $origen);

            // f) Bitácora con los datos nuevos.
            Bitacora::registrar("PROGRAMAR_CITA", "citas", $usuario->id, true, "citas", $cita->id, "WEB", null, $this->foto($cita));

            return $cita;
        });
    }

    /**
     * CU16 pasos 5 a 7 (RF08): cambia la cita a otra ficha del MISMO médico y especialidad.
     * $autoservicio: el paciente o su responsable legal desde la web (A2).
     */
    public function reprogramar(Cita $cita, int $nuevaFichaId, Usuario $usuario, ?string $motivo = null, bool $autoservicio = false): Cita
    {
        return $this->enTransaccion(function () use ($cita, $nuevaFichaId, $usuario, $motivo, $autoservicio) {
            // a) La cita queda bloqueada (FOR UPDATE) y se leen sus datos actuales.
            $cita = $this->bloquearCita($cita);

            // b) RN12 / A2: recepción con citas.reprogramar o el dueño con citas.autoservicio.
            $this->autorizarCambio($cita, $usuario, $autoservicio, "citas.reprogramar");

            // c) RF08 / RN21 / A2: PROGRAMADA o CONFIRMADA, sin comenzar y, por la web, con anticipación.
            $this->validarCambioPosible($cita, "reprogramar", $autoservicio);

            // d) RN18: máximo de reprogramaciones (max_reprogramaciones).
            if ($cita->reprogramacionesRestantes() <= 0) {
                throw new ReglaNegocioException("La cita ya se reprogramó " . Cita::parametro("max_reprogramaciones") . " veces. Cancélela y registre una cita nueva.");
            }

            // e) RN13: la nueva ficha queda bloqueada; R-SOLAPE / RN17: y las reservas del paciente.
            $ficha = $this->bloquearFicha($nuevaFichaId);
            $this->bloquearPaciente($cita->paciente_id);

            // f) RF08: mismo médico y especialidad, distinta de la actual.
            $actual = $cita->ficha->horario->medicoEspecialidad;
            $nueva = $ficha->horario->medicoEspecialidad;
            if ($nueva->medico_id !== $actual->medico_id || $nueva->especialidad_id !== $actual->especialidad_id) {
                throw new ReglaNegocioException("La nueva ficha debe ser del mismo médico y la misma especialidad. Para cambiar de médico use «Reasignar».");
            }
            if ($ficha->id === $cita->ficha_id) {
                throw new ReglaNegocioException("Elija una ficha distinta de la actual.");
            }

            // g) RN04–RN07 / RF07: habilitada, futura, de un horario vigente, médico activo y libre.
            $inicio = $ficha->inicio();
            $this->validarFicha($ficha, $inicio);

            // h) RN15–RN17: el paciente en la nueva fecha, sin contar esta misma cita en el cruce.
            $this->validarPacienteEnLaFecha($cita->paciente, $ficha, $inicio, $cita->id);

            // i) CU16 paso 6: nueva ficha y vuelve a PROGRAMADA (debe confirmarse otra vez).
            $antes = $this->foto($cita);
            $fichaAnterior = $cita->ficha_id;
            $estadoAnterior = $cita->estado;
            $cita->update([
                "ficha_id" => $ficha->id,
                "estado" => "PROGRAMADA",
                "enlace_virtual" => $this->enlaceVirtual($ficha, $cita->codigo, $cita->enlace_virtual),
            ]);
            $cita->setRelation("ficha", $ficha);

            // RN18: el historial con ficha anterior y nueva es el que cuenta las reprogramaciones.
            HistorialCita::create([
                "cita_id" => $cita->id,
                "usuario_realiza_id" => $usuario->id,
                "ficha_anterior_id" => $fichaAnterior,
                "ficha_nueva_id" => $ficha->id,
                "estado_anterior" => $estadoAnterior,
                "estado_nuevo" => "PROGRAMADA",
                "canal" => "WEB",
                "motivo" => $this->texto($motivo) ?? ($autoservicio ? "Reprogramada por el paciente en línea" : "Reprogramada por recepción"),
            ]);

            // j) CU16 paso 7: los avisos pendientes se anulan y se crean los de la nueva fecha.
            $this->cancelarAvisosPendientes($cita);
            $this->crearNotificaciones($cita, $usuario, "CITA_REPROGRAMADA");

            Bitacora::registrar("REPROGRAMAR_CITA", "citas", $usuario->id, true, "citas", $cita->id, "WEB", $antes,
                $this->foto($cita) + ["motivo_cambio" => $this->texto($motivo)]);

            return $cita;
        });
    }

    /**
     * CU16 A1: reasigna la cita a una ficha de OTRO médico ACTIVO de la MISMA especialidad. Cancela la
     * original y crea una cita nueva que la reemplaza (cita_reemplazada_id). No cuenta como reprogramación.
     */
    public function reasignar(Cita $cita, int $fichaOtroMedicoId, Usuario $usuario, ?string $motivo = null): Cita
    {
        return $this->enTransaccion(function () use ($cita, $fichaOtroMedicoId, $usuario, $motivo) {
            // a) La cita original queda bloqueada; RN12: solo recepción con citas.reprogramar.
            $original = $this->bloquearCita($cita);
            $this->autorizarCambio($original, $usuario, false, "citas.reprogramar");
            $this->validarCambioPosible($original, "reasignar", false);

            // b) A1: la ficha es de otro médico de la misma especialidad (el resto lo valida registrarCita).
            $ficha = Ficha::with("horario.medicoEspecialidad.especialidad")->find($fichaOtroMedicoId);
            if ($ficha === null) {
                throw new ReglaNegocioException("La ficha elegida no existe. Elija otro horario disponible.");
            }
            $actual = $original->ficha->horario->medicoEspecialidad;
            if ($ficha->horario->medicoEspecialidad->especialidad_id !== $actual->especialidad_id) {
                throw new ReglaNegocioException("La nueva ficha debe ser de la especialidad {$actual->especialidad->nombre}.");
            }
            if ($ficha->horario->medicoEspecialidad->medico_id === $actual->medico_id) {
                throw new ReglaNegocioException("La ficha es del mismo médico: para cambiar solo el horario use «Reprogramar».");
            }

            // c) Se cancela la original (deja libre su ficha) con el motivo «Reasignada a otro médico».
            $antes = $this->foto($original);
            $this->anular($original, "Reasignada a otro médico" . ($this->texto($motivo) ? ": " . $this->texto($motivo) : ""), $usuario);

            // d) Cita nueva con las mismas reglas que CU15 (mismo paciente, motivo y seguro), origen RECEPCION.
            $nueva = $this->registrarCita([
                "paciente_id" => $original->paciente_id,
                "ficha_id" => $ficha->id,
                "motivo" => $original->motivo,
                "seguro_paciente_id" => $original->seguro_paciente_id,
                "codigo_autorizacion_seguro" => $original->codigo_autorizacion_seguro,
            ], $usuario, self::ORIGEN_RECEPCION, $original);

            Bitacora::registrar("REASIGNAR_CITA", "citas", $usuario->id, true, "citas", $nueva->id, "WEB", $antes,
                $this->foto($nueva) + ["reemplaza_a" => $original->codigo, "motivo_cambio" => $this->texto($motivo)]);

            return $nueva;
        });
    }

    /**
     * CU17 pasos 4 a 7 (RF09): cancela la cita SIN eliminar el registro; la ficha vuelve a quedar
     * disponible (RN09: el índice parcial ux_citas_ficha_activa ya no la cuenta).
     * $autoservicio: el paciente o su responsable legal desde la web (A1).
     */
    public function cancelar(Cita $cita, string $motivo, Usuario $usuario, bool $autoservicio = false): Cita
    {
        return DB::transaction(function () use ($cita, $motivo, $usuario, $autoservicio) {
            $cita = $this->bloquearCita($cita);

            // RN12 / A1: recepción con citas.cancelar o el dueño con citas.autoservicio.
            $this->autorizarCambio($cita, $usuario, $autoservicio, "citas.cancelar");

            // RF09 / RN21 / A1: PROGRAMADA o CONFIRMADA, sin comenzar y, por la web, con anticipación.
            $this->validarCambioPosible($cita, "cancelar", $autoservicio);

            // ck_citas_cancelacion: el motivo es obligatorio.
            $motivo = $this->texto($motivo);
            if ($motivo === null) {
                throw new ReglaNegocioException("El motivo de cancelación es obligatorio.");
            }

            $antes = $this->foto($cita);
            $this->anular($cita, $motivo, $usuario);

            Bitacora::registrar("CANCELAR_CITA", "citas", $usuario->id, true, "citas", $cita->id, "WEB", $antes, $this->foto($cita));

            return $cita;
        });
    }

    /**
     * CU18 (RF10): confirmar (CONFIRMADA), registrar la atención (ATENDIDA) o la inasistencia
     * (INASISTENCIA, A1). Revisa el permiso (RN12), la transición y el momento (RN21).
     */
    public function cambiarEstado(Cita $cita, string $nuevo, Usuario $usuario): Cita
    {
        return DB::transaction(function () use ($cita, $nuevo, $usuario) {
            $cita = $this->bloquearCita($cita);
            $estadoAnterior = $cita->estado;

            // RN12: CU18 paso 3 requiere citas.confirmar; paso 4 y A1 citas.registrar_asistencia
            // y, para el médico, que la cita sea suya (A2).
            $permitido = match ($nuevo) {
                "CONFIRMADA" => $usuario->tienePermiso("citas.confirmar"),
                "ATENDIDA", "INASISTENCIA" => $cita->puedeRegistrarAsistencia($usuario),
                default => true,
            };
            if (! $permitido) {
                throw new AuthorizationException("No tiene permiso para cambiar el estado de esta cita.");
            }

            // RF10: solo las transiciones de la máquina de estados (CANCELADA y PROGRAMADA tienen su propio caso de uso).
            if (! in_array($nuevo, ["CONFIRMADA", "ATENDIDA", "INASISTENCIA"], true) || ! $cita->puedePasarA($nuevo)) {
                throw new ReglaNegocioException("Transición no permitida: una cita " . mb_strtolower($estadoAnterior) . " no puede pasar a " . mb_strtolower($nuevo) . ".");
            }

            // RN21 / R-HORA: el momento de cada transición.
            $hora = substr($cita->ficha->hora_inicio, 0, 5);
            if ($nuevo === "CONFIRMADA" && $cita->comenzo()) {
                throw new ReglaNegocioException("La cita ya comenzó o pasó: no se puede confirmar.");
            }
            if ($nuevo === "ATENDIDA" && ! $cita->comenzo()) {
                throw new ReglaNegocioException("No se puede marcar como atendida antes de la hora de la cita ({$hora}).");
            }
            if ($nuevo === "INASISTENCIA") {
                if ($cita->inicioInasistencia()->isFuture()) {
                    throw new ReglaNegocioException("Solo se puede registrar la inasistencia desde las " . $cita->inicioInasistencia()->format("H:i")
                        . " (" . Cita::parametro("tolerancia_inasistencia_min") . " minutos de tolerancia).");
                }
                // CU18 A1: con una consulta registrada el paciente sí vino.
                if ($cita->consulta()->exists()) {
                    throw new ReglaNegocioException("La cita tiene una consulta registrada: no se puede marcar como inasistencia.");
                }
            }

            // CU18 paso 5: estado nuevo, historial y bitácora.
            $antes = $this->foto($cita);
            $cita->update(["estado" => $nuevo]);

            HistorialCita::create([
                "cita_id" => $cita->id,
                "usuario_realiza_id" => $usuario->id,
                "estado_anterior" => $estadoAnterior,
                "estado_nuevo" => $nuevo,
                "canal" => "WEB",
                "motivo" => match ($nuevo) {
                    "CONFIRMADA" => "Asistencia confirmada",
                    "ATENDIDA" => "Paciente atendido",
                    default => "El paciente no se presentó",
                },
            ]);

            // CU18 paso 5: la cita ya ocurrió (o no): sus recordatorios pendientes se anulan.
            if (in_array($nuevo, ["ATENDIDA", "INASISTENCIA"], true)) {
                $this->cancelarAvisosPendientes($cita);
            }

            Bitacora::registrar("CAMBIO_ESTADO_CITA", "citas", $usuario->id, true, "citas", $cita->id, "WEB", $antes, $this->foto($cita));

            return $cita;
        });
    }

    /**
     * RF06 / CU15 paso 3: SOLO las fichas disponibles de un médico en una especialidad y fecha:
     * HABILITADAS, futuras, de horarios ACTIVOS y vigentes y sin cita activa, ordenadas por hora.
     */
    public function fichasDisponibles(int $medicoId, int $especialidadId, Carbon $fecha): Collection
    {
        return $this->consultaFichas()
            ->where("me.medico_id", $medicoId)
            ->where("me.especialidad_id", $especialidadId)
            ->where("fichas.fecha", $fecha->toDateString())
            ->where("fichas.estado", "HABILITADA")
            ->whereNotExists($this->citaActivaDeLaFicha())
            ->orderBy("fichas.hora_inicio")
            ->get();
    }

    // CU15 / CU16 paso 3 (sin fichas ese día): la fecha más próxima, después de $despuesDe y hasta $dias
    // días más tarde, con alguna ficha disponible del médico en la especialidad (o null).
    public function proximaFechaDisponible(int $medicoId, int $especialidadId, Carbon $despuesDe, int $dias = 60): ?Carbon
    {
        $fecha = $this->consultaFichas()
            ->where("me.medico_id", $medicoId)
            ->where("me.especialidad_id", $especialidadId)
            ->where("fichas.fecha", ">", $despuesDe->toDateString())
            ->where("fichas.fecha", "<=", $despuesDe->copy()->addDays($dias)->toDateString())
            ->where("fichas.estado", "HABILITADA")
            ->whereNotExists($this->citaActivaDeLaFicha())
            ->min("fichas.fecha");

        return $fecha ? Carbon::parse($fecha) : null;
    }

    /**
     * CU14 / RF06: consulta base de las fichas que se pueden mostrar: de horarios ACTIVOS y vigentes
     * en la fecha de la ficha (RN05), del día y dentro de las horas del horario, de médicos,
     * especialidades y asignaciones ACTIVOS (RN04) y con inicio futuro (RN06). Trae los datos del
     * médico, la especialidad, la modalidad y el consultorio. Con parámetros.
     */
    public function consultaFichas(): Builder
    {
        return Ficha::join("horarios_atencion as h", "h.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades as me", "me.id", "=", "h.medico_especialidad_id")
            ->join("medicos", "medicos.id", "=", "me.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->join("especialidades", "especialidades.id", "=", "me.especialidad_id")
            ->leftJoin("consultorios", "consultorios.id", "=", "h.consultorio_id")
            ->where("h.estado", "ACTIVO")
            ->where("me.estado", "ACTIVO")
            ->where("medicos.estado", "ACTIVO")
            ->where("especialidades.estado", "ACTIVO")
            ->whereColumn("fichas.fecha", ">=", "h.vigente_desde")
            ->where(fn ($vigencia) => $vigencia->whereNull("h.vigente_hasta")->orWhereColumn("fichas.fecha", "<=", "h.vigente_hasta"))
            ->whereRaw("extract(isodow from fichas.fecha) = h.dia_semana")
            ->whereColumn("fichas.hora_inicio", ">=", "h.hora_inicio")
            ->whereColumn("fichas.hora_fin", "<=", "h.hora_fin")
            ->whereRaw("(fichas.fecha + fichas.hora_inicio) > ?", [now()->format("Y-m-d H:i:s")])
            ->select("fichas.*", "h.modalidad", "h.consultorio_id", "me.medico_id", "me.especialidad_id",
                "especialidades.nombre as especialidad", "personas.nombres as medico_nombres", "personas.apellido_paterno as medico_apellido",
                "personas.apellido_materno as medico_apellido_materno", "personas.sexo as medico_sexo",
                "consultorios.nombre as consultorio", "consultorios.ubicacion as ubicacion");
    }

    // R-OCUP: subconsulta «la ficha tiene una cita activa» (estado distinto de CANCELADA).
    public function citaActivaDeLaFicha(): \Closure
    {
        return fn ($consulta) => $consulta->selectRaw("1")->from("citas")
            ->whereColumn("citas.ficha_id", "fichas.id")
            ->where("citas.estado", "<>", "CANCELADA");
    }

    // «Dr.» o «Dra.» con el nombre completo del médico.
    public static function tituloMedico(Persona $persona): string
    {
        return ($persona->sexo === "F" ? "Dra. " : "Dr. ") . $persona->nombre_completo;
    }

    // RF07: transacción que traduce el 23505 de ux_citas_ficha_activa (otro usuario tomó la ficha
    // en el mismo instante) a un mensaje sin detalles internos.
    private function enTransaccion(\Closure $operacion): Cita
    {
        try {
            return DB::transaction($operacion);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === "23505" && str_contains($e->getMessage(), "ux_citas_ficha_activa")) {
                throw new ReglaNegocioException("La ficha acaba de ser tomada por otro usuario. Elija otro horario disponible.");
            }

            throw $e;
        }
    }

    /**
     * CU15 pasos 6 a 9 (lo reutiliza CU16 A1): valida y crea la cita con su historial y sus avisos,
     * dentro de la transacción del llamador. $reemplaza: la cita CANCELADA que esta reemplaza.
     */
    private function registrarCita(array $datos, Usuario $usuario, string $origen, ?Cita $reemplaza = null): Cita
    {
        // a) La ficha queda bloqueada (FOR UPDATE) hasta el final de la transacción.
        $ficha = $this->bloquearFicha((int) $datos["ficha_id"]);

        $paciente = Paciente::with("persona")->find($datos["paciente_id"]);
        if ($paciente === null) {
            throw new ReglaNegocioException("El paciente elegido no existe.");
        }

        // a) R-SOLAPE / RN17: serializa las reservas del mismo paciente.
        $this->bloquearPaciente($paciente->id);

        // b) Reglas de negocio.
        $inicio = $ficha->inicio();
        if ($origen === self::ORIGEN_AUTOSERVICIO) {
            $this->validarAutoservicio($usuario, $paciente, $inicio);
        }
        $this->validarPaciente($paciente);
        $this->validarFicha($ficha, $inicio);
        $this->validarPacienteEnLaFecha($paciente, $ficha, $inicio);
        $seguro = $this->validarSeguro($datos["seguro_paciente_id"] ?? null, $paciente, $inicio);

        // c) La cita, con su código CM- siguiente.
        $codigo = $this->siguienteCodigo();
        $cita = Cita::create([
            "usuario_registra_id" => $usuario->id,
            "paciente_id" => $paciente->id,
            "ficha_id" => $ficha->id,
            "seguro_paciente_id" => $seguro?->id,
            // CU16 A1 / tg_citas_reemplazo: la original ya está CANCELADA y es del mismo paciente.
            "cita_reemplazada_id" => $reemplaza?->id,
            "codigo" => $codigo,
            "origen" => $origen,
            "canal" => "WEB",
            "motivo" => ($datos["motivo"] ?? null) ?: null,
            "estado" => "PROGRAMADA",
            "codigo_autorizacion_seguro" => $seguro ? (($datos["codigo_autorizacion_seguro"] ?? null) ?: null) : null,
            "enlace_virtual" => $this->enlaceVirtual($ficha, $codigo),
            "fecha_registro" => now(),
        ]);
        $cita->setRelation("ficha", $ficha);
        $cita->setRelation("paciente", $paciente);

        // d) CU15 paso 9: primer registro del historial.
        HistorialCita::create([
            "cita_id" => $cita->id,
            "usuario_realiza_id" => $usuario->id,
            "ficha_nueva_id" => $ficha->id,
            "estado_anterior" => null,
            "estado_nuevo" => "PROGRAMADA",
            "canal" => "WEB",
            "motivo" => match (true) {
                $reemplaza !== null => "Reasignada desde la cita {$reemplaza->codigo}",
                $origen === self::ORIGEN_AUTOSERVICIO => "Reserva en línea del paciente",
                default => "Programada por recepción",
            },
        ]);

        // e) CU15 paso 9: avisos pendientes (el envío es del Ciclo 2).
        $this->crearNotificaciones($cita, $usuario, "CITA_PROGRAMADA");

        return $cita;
    }

    /**
     * CU17 pasos 5 y 6 (lo reutiliza CU16 A1): CANCELADA con su motivo, sin eliminar el registro
     * (RF09, RN10), historial, avisos pendientes anulados y CITA_CANCELADA al paciente y al médico.
     */
    private function anular(Cita $cita, string $motivo, Usuario $usuario): void
    {
        $estadoAnterior = $cita->estado;
        $cita->update(["estado" => "CANCELADA", "motivo_cancelacion" => $motivo]);

        HistorialCita::create([
            "cita_id" => $cita->id,
            "usuario_realiza_id" => $usuario->id,
            "estado_anterior" => $estadoAnterior,
            "estado_nuevo" => "CANCELADA",
            "canal" => "WEB",
            "motivo" => $motivo,
        ]);

        $this->cancelarAvisosPendientes($cita);
        $this->crearNotificaciones($cita, $usuario, "CITA_CANCELADA");
    }

    // RN13: la cita bloqueada (FOR UPDATE) con su paciente y su ficha, leída de nuevo dentro de la transacción.
    private function bloquearCita(Cita $cita): Cita
    {
        $bloqueada = Cita::whereKey($cita->id)->lockForUpdate()->first();
        if ($bloqueada === null) {
            throw new ReglaNegocioException("La cita no existe.");
        }

        return $bloqueada->load(array_merge(["paciente.persona"], array_map(fn ($relacion) => "ficha." . $relacion, self::RELACIONES_FICHA)));
    }

    // RN13 / RF07: la ficha bloqueada (FOR UPDATE) hasta el final de la transacción.
    private function bloquearFicha(int $fichaId): Ficha
    {
        $ficha = Ficha::whereKey($fichaId)->lockForUpdate()->first();
        if ($ficha === null) {
            throw new ReglaNegocioException("La ficha elegida no existe. Elija otro horario disponible.");
        }

        return $ficha->load(self::RELACIONES_FICHA);
    }

    // R-SOLAPE / RN17: candado de la transacción para las reservas del mismo paciente.
    private function bloquearPaciente(int $pacienteId): void
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["citas.paciente." . $pacienteId]);
    }

    // RN12: recepción con $permiso o, por la web (CU16 A2 / CU17 A1), el paciente dueño o su
    // responsable legal verificado con citas.autoservicio. Si no, 403.
    private function autorizarCambio(Cita $cita, Usuario $usuario, bool $autoservicio, string $permiso): void
    {
        $permitido = $autoservicio
            ? $usuario->tienePermiso("citas.autoservicio") && $cita->esDeLaPersona($usuario->persona_id)
            : $usuario->tienePermiso($permiso);
        if (! $permitido) {
            throw new AuthorizationException("No puede modificar esta cita.");
        }
    }

    // RF08 / RF09 / RN21: la cita está PROGRAMADA o CONFIRMADA y no ha comenzado; por la web, además,
    // faltan al menos horas_minimas_cambio_web horas (CU16 A2 / CU17 A1).
    private function validarCambioPosible(Cita $cita, string $accion, bool $autoservicio): void
    {
        $estado = mb_strtolower($cita->estado);
        if (! in_array($cita->estado, Cita::ESTADOS_VIGENTES, true)) {
            if ($accion === "cancelar") {
                // RF09: una cita atendida no se cancela como si no hubiera ocurrido.
                throw new ReglaNegocioException($cita->estado === "ATENDIDA" ? "Una cita atendida no se puede cancelar: ya ocurrió." : "La cita ya está {$estado}.");
            }
            throw new ReglaNegocioException("Una cita {$estado} no se puede {$accion}.");
        }

        if ($cita->comenzo()) {
            throw new ReglaNegocioException($accion === "cancelar"
                ? "La cita ya comenzó o pasó: registre la atención o la inasistencia."
                : "La cita ya comenzó o pasó: no se puede {$accion}.");
        }

        if ($autoservicio && ! $cita->aTiempoParaLaWeb()) {
            throw new ReglaNegocioException("Las citas solo se pueden {$accion} por la web con al menos "
                . Cita::parametro("horas_minimas_cambio_web") . " horas de anticipación. Comuníquese con recepción.");
        }
    }

    // CU15 A1: el correo verificado, el paciente propio o un menor a cargo (R-TUTOR) y RN19.
    private function validarAutoservicio(Usuario $usuario, Paciente $paciente, Carbon $inicio): void
    {
        if ($usuario->correo_verificado_en === null) {
            throw new ReglaNegocioException("Verifica tu correo para reservar en línea.");
        }

        // R-TUTOR: la propia persona o un menor del que es responsable legal ACTIVO y verificado.
        $esPropio = $paciente->persona_id === $usuario->persona_id;
        $esTutor = ! $esPropio && $paciente->esMenor($inicio) && $this->esResponsableVerificado($usuario->persona_id, $paciente->id);
        if (! $esPropio && ! $esTutor) {
            throw new AuthorizationException("No puede reservar citas para este paciente.");
        }

        // RN19: demasiadas inasistencias recientes bloquean la reserva en línea (valores de configuraciones_sistema).
        $maximo = ConfiguracionSistema::entero("max_inasistencias_autoreserva");
        $meses = ConfiguracionSistema::entero("meses_inasistencias");
        $inasistencias = Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->where("citas.paciente_id", $paciente->id)
            ->where("citas.estado", "INASISTENCIA")
            ->where("fichas.fecha", ">=", today()->subMonths($meses)->toDateString())
            ->where("fichas.fecha", "<=", today()->toDateString())
            ->count();
        if ($inasistencias >= $maximo) {
            throw new ReglaNegocioException("Tienes {$inasistencias} inasistencias en los últimos {$meses} meses: la reserva en línea está bloqueada. Comunícate con recepción.");
        }
    }

    // RN03: solo un paciente ACTIVO recibe citas nuevas.
    private function validarPaciente(Paciente $paciente): void
    {
        if ($paciente->estado !== "ACTIVO") {
            throw new ReglaNegocioException("El paciente está inactivo y no puede recibir citas nuevas.");
        }
    }

    // RN04–RN07: ficha habilitada, futura, de un horario vigente, de un médico activo y libre.
    private function validarFicha(Ficha $ficha, Carbon $inicio): void
    {
        $horario = $ficha->horario;
        $asignacion = $horario->medicoEspecialidad;
        $medico = $asignacion->medico;

        if ($ficha->estado !== "HABILITADA") {
            throw new ReglaNegocioException("La ficha está bloqueada (feriado o bloqueo de agenda).");
        }

        // RN06: no se reserva en el pasado.
        if (! $inicio->isFuture()) {
            throw new ReglaNegocioException("No se pueden registrar citas en fechas u horas pasadas.");
        }

        // RN05: horario ACTIVO, vigente en la fecha de la ficha, del mismo día y la ficha dentro de sus horas.
        $fecha = $inicio->toDateString();
        $vigente = $horario->estado === "ACTIVO"
            && $fecha >= Carbon::parse($horario->vigente_desde)->toDateString()
            && ($horario->vigente_hasta === null || $fecha <= Carbon::parse($horario->vigente_hasta)->toDateString())
            && $inicio->dayOfWeekIso === (int) $horario->dia_semana
            && $ficha->hora_inicio >= $horario->hora_inicio && $ficha->hora_fin <= $horario->hora_fin;
        if (! $vigente) {
            throw new ReglaNegocioException("El horario de atención de esa ficha ya no está vigente. Elija otro horario disponible.");
        }

        // RN04: médico ACTIVO; la especialidad y la asignación también.
        if ($medico->estado !== "ACTIVO") {
            throw new ReglaNegocioException("El médico está inactivo y no puede recibir citas nuevas.");
        }
        if ($asignacion->estado !== "ACTIVO" || $asignacion->especialidad->estado !== "ACTIVO") {
            throw new ReglaNegocioException("La especialidad {$asignacion->especialidad->nombre} no está disponible con ese médico.");
        }

        // RN07 / RF07: la ficha no tiene otra cita activa (mensaje explicativo).
        if ($ficha->citaActiva()->exists()) {
            $persona = $medico->persona;
            throw new ReglaNegocioException("El horario de " . substr($ficha->hora_inicio, 0, 5) . " a " . substr($ficha->hora_fin, 0, 5) . " "
                . ($persona->sexo === "F" ? "de la " : "del ") . self::tituloMedico($persona)
                . " ya está ocupado por otra cita. Elija otro horario disponible.");
        }
    }

    // RN15, RN16 y RN17: reglas del paciente en la FECHA DE LA CITA. $sinContarCitaId: la cita que
    // se reprograma (CU16) no cuenta como cruce consigo misma.
    private function validarPacienteEnLaFecha(Paciente $paciente, Ficha $ficha, Carbon $inicio, ?int $sinContarCitaId = null): void
    {
        $persona = $paciente->persona;

        // RN15 / R-EDAD: edad y sexo que admite la especialidad.
        $especialidad = $ficha->horario->medicoEspecialidad->especialidad;
        if ($mensaje = $especialidad->restriccionPara($persona->edadEn($inicio), $persona->sexo)) {
            throw new ReglaNegocioException($mensaje);
        }

        // RN16: un menor necesita un responsable legal ACTIVO y verificado.
        if ($paciente->esMenor($inicio) && ! $paciente->tieneResponsableVerificado()) {
            throw new ReglaNegocioException("El paciente es menor de edad y no tiene un responsable legal verificado. Regístrelo en Vínculos.");
        }

        // RN17: el paciente no tiene otra cita vigente que se cruce, aunque sea con otro médico.
        $cruce = Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->where("citas.paciente_id", $paciente->id)
            ->whereIn("citas.estado", Cita::ESTADOS_VIGENTES)
            ->when($sinContarCitaId, fn ($consulta) => $consulta->where("citas.id", "<>", $sinContarCitaId))
            ->where("fichas.fecha", $inicio->toDateString())
            ->where("fichas.hora_inicio", "<", $ficha->hora_fin)
            ->where("fichas.hora_fin", ">", $ficha->hora_inicio)
            ->select("citas.codigo", "fichas.hora_inicio", "fichas.hora_fin")
            ->first();
        if ($cruce) {
            throw new ReglaNegocioException("El paciente ya tiene la cita {$cruce->codigo} de " . substr($cruce->hora_inicio, 0, 5) . " a " . substr($cruce->hora_fin, 0, 5) . " ese día.");
        }
    }

    // RN20: seguro opcional, del mismo paciente, ACTIVO, verificado y vigente en la fecha de la cita.
    private function validarSeguro($seguroId, Paciente $paciente, Carbon $inicio): ?PacienteSeguro
    {
        if (empty($seguroId)) {
            return null;
        }

        $seguro = PacienteSeguro::find($seguroId);
        if ($seguro === null || $seguro->paciente_id !== $paciente->id) {
            throw new ReglaNegocioException("El seguro elegido no pertenece al paciente.");
        }

        $fecha = $inicio->toDateString();
        if ($seguro->estado !== "ACTIVO" || $seguro->fecha_verificacion === null
            || $fecha < $seguro->vigente_desde->toDateString() || $fecha > $seguro->vigente_hasta->toDateString()) {
            throw new ReglaNegocioException("El seguro no está verificado o no está vigente en la fecha de la cita.");
        }

        return $seguro;
    }

    // R-TUTOR: la persona es responsable legal ACTIVO y verificado del paciente.
    private function esResponsableVerificado(int $personaId, int $pacienteId): bool
    {
        return VinculoPaciente::where("paciente_id", $pacienteId)
            ->where("persona_id", $personaId)
            ->where("estado", "ACTIVO")
            ->where("es_responsable_legal", true)
            ->whereNotNull("fecha_verificacion")
            ->exists();
    }

    // CU15 paso 7: código CM-NNNNNN siguiente. El candado de la transacción evita que dos
    // registros simultáneos tomen el mismo número.
    private function siguienteCodigo(): string
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["citas.codigo"]);
        $ultimo = DB::selectOne('SELECT coalesce(max(substring(codigo from 4)::int), 0) AS n FROM citas WHERE codigo ~ \'^CM-[0-9]+$\'')->n;

        return sprintf("CM-%06d", $ultimo + 1);
    }

    // CU15 A4: la atención virtual lleva su enlace de videollamada (al reprogramar se conserva el que tenía).
    private function enlaceVirtual(Ficha $ficha, string $codigo, ?string $actual = null): ?string
    {
        if ($ficha->horario->modalidad !== "VIRTUAL") {
            return null;
        }

        return $actual ?? "https://meet.jit.si/ClinicaVidaSalud-" . $codigo;
    }

    // CU16 paso 7 / CU17 paso 6 / CU18 paso 5: los avisos PENDIENTES de la cita pasan a CANCELADA (no se eliminan).
    private function cancelarAvisosPendientes(Cita $cita): void
    {
        Notificacion::where("referencia_tipo", "citas")
            ->where("referencia_id", $cita->id)
            ->where("estado", "PENDIENTE")
            ->update(["estado" => "CANCELADA"]);
    }

    /**
     * CU15 paso 9 / CU16 paso 7 / CU17 paso 6: aviso $tipo (CITA_PROGRAMADA, CITA_REPROGRAMADA o
     * CITA_CANCELADA) PENDIENTE al paciente (o a su responsable legal verificado si es menor) y al
     * médico por SISTEMA. Si la cita sigue vigente, también el RECORDATORIO_CITA.
     */
    private function crearNotificaciones(Cita $cita, Usuario $usuario, string $tipo): void
    {
        $ficha = $cita->ficha;
        $paciente = $cita->paciente;
        $inicio = $ficha->inicio();
        $horario = $ficha->horario;
        $medicoPersona = $horario->medicoEspecialidad->medico->persona;
        $especialidad = $horario->medicoEspecialidad->especialidad->nombre;
        $cuando = $inicio->format("d/m/Y") . " de " . substr($ficha->hora_inicio, 0, 5) . " a " . substr($ficha->hora_fin, 0, 5);
        $lugar = $horario->modalidad === "VIRTUAL"
            ? "en línea (enlace: {$cita->enlace_virtual})"
            : "en {$horario->consultorio->nombre} ({$horario->consultorio->ubicacion})";
        $nombre = $paciente->persona->nombre_completo;
        $detalle = "{$nombre} con " . self::tituloMedico($medicoPersona) . " ({$especialidad}) el {$cuando} {$lugar}";

        // Destinatario: el paciente o, si es menor en la fecha de la cita, su responsable legal verificado
        // (si el cambio lo hace un responsable, él mismo).
        $destinatario = $paciente->persona;
        if ($paciente->esMenor($inicio)) {
            $vinculo = VinculoPaciente::with("persona.usuario")
                ->where("paciente_id", $paciente->id)
                ->where("estado", "ACTIVO")
                ->where("es_responsable_legal", true)
                ->whereNotNull("fecha_verificacion")
                ->orderByRaw("CASE WHEN persona_id = ? THEN 0 ELSE 1 END", [$usuario->persona_id])
                ->orderBy("id")
                ->first();
            $destinatario = $vinculo?->persona ?? $destinatario;
        }

        // Canal: CORREO si tiene cuenta que acepta avisos por correo; si no, SISTEMA.
        $cuenta = $destinatario->usuario;
        $canal = $cuenta && $cuenta->notificar_por_correo && $cuenta->correo ? "CORREO" : "SISTEMA";
        $base = ["referencia_tipo" => "citas", "referencia_id" => $cita->id, "estado" => "PENDIENTE"];

        // Títulos y mensajes para el paciente y para el médico según el tipo de aviso.
        [$tituloPaciente, $mensajePaciente, $tituloMedico, $mensajeMedico] = match ($tipo) {
            "CITA_REPROGRAMADA" => [
                "Cita {$cita->codigo} reprogramada", "La cita {$cita->codigo} se reprogramó: {$detalle}. Debe confirmarse otra vez.",
                "Cita {$cita->codigo} reprogramada", "La cita {$cita->codigo} de {$nombre} se reprogramó al {$cuando}.",
            ],
            "CITA_CANCELADA" => [
                "Cita {$cita->codigo} cancelada", "Se canceló la cita {$cita->codigo} de {$detalle}. Motivo: {$cita->motivo_cancelacion}",
                "Cita {$cita->codigo} cancelada", "Se canceló la cita {$cita->codigo} de {$nombre} del {$cuando}. El horario quedó disponible.",
            ],
            default => [
                "Cita {$cita->codigo} programada", "Se programó la cita {$cita->codigo} de {$detalle}.",
                "Nueva cita {$cita->codigo}", "Nueva cita {$cita->codigo}: {$nombre} el {$cuando}.",
            ],
        };

        Notificacion::create($base + [
            "persona_id" => $destinatario->id,
            "tipo" => $tipo,
            "canal" => $canal,
            "titulo" => $tituloPaciente,
            "mensaje" => $mensajePaciente,
            "programada_para" => now(),
        ]);

        // Recordatorio (solo si la cita sigue vigente): horas_anticipacion de la cuenta (24 si no tiene),
        // solo si ese momento es futuro.
        $recordar = $inicio->copy()->subHours($cuenta?->horas_anticipacion ?? 24);
        if ($tipo !== "CITA_CANCELADA" && $recordar->isFuture()) {
            Notificacion::create($base + [
                "persona_id" => $destinatario->id,
                "tipo" => "RECORDATORIO_CITA",
                "canal" => $canal,
                "titulo" => "Recordatorio de la cita {$cita->codigo}",
                "mensaje" => "Le recordamos la cita {$cita->codigo} de {$detalle}.",
                "programada_para" => $recordar,
            ]);
        }

        // Al médico, por el sistema.
        Notificacion::create($base + [
            "persona_id" => $medicoPersona->id,
            "tipo" => $tipo,
            "canal" => "SISTEMA",
            "titulo" => $tituloMedico,
            "mensaje" => $mensajeMedico,
            "programada_para" => now(),
        ]);
    }

    // Texto recortado o null si queda vacío.
    private function texto(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === "" ? null : $valor;
    }

    // Datos de la cita para la bitácora (anteriores y nuevos).
    private function foto(Cita $cita): array
    {
        $ficha = $cita->ficha;
        $asignacion = $ficha->horario->medicoEspecialidad;

        return [
            "codigo" => $cita->codigo,
            "paciente" => $cita->paciente->codigo,
            "ficha_id" => $ficha->id,
            "fecha" => Carbon::parse($ficha->fecha)->toDateString(),
            "hora_inicio" => substr($ficha->hora_inicio, 0, 5),
            "hora_fin" => substr($ficha->hora_fin, 0, 5),
            "medico" => $asignacion->medico->codigo,
            "especialidad" => $asignacion->especialidad->nombre,
            "origen" => $cita->origen,
            "estado" => $cita->estado,
            "seguro_paciente_id" => $cita->seguro_paciente_id,
            "enlace_virtual" => $cita->enlace_virtual,
            "motivo_cancelacion" => $cita->motivo_cancelacion,
            "cita_reemplazada_id" => $cita->cita_reemplazada_id,
        ];
    }
}
