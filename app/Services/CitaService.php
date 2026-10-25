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
 * Lógica de negocio de las citas (RF06, RF07, RN03–RN09, RN13, RN15–RN20). La usan CU15 Programar
 * cita y, más adelante, CU16 Reprogramar, CU17 Cancelar y CU18. Toda escritura va en UNA
 * transacción; las reglas que no se cumplen lanzan ReglaNegocioException con el mensaje en español.
 */
class CitaService
{
    public const ORIGEN_RECEPCION = "RECEPCION";
    public const ORIGEN_AUTOSERVICIO = "AUTOSERVICIO";

    // RN17: estados de una cita que ocupa al paciente.
    private const ESTADOS_VIGENTES = ["PROGRAMADA", "CONFIRMADA"];

    /**
     * CU15 pasos 6 a 9: programa una cita.
     * $datos: paciente_id, ficha_id, motivo, seguro_paciente_id y codigo_autorizacion_seguro (opcionales).
     * $origen: RECEPCION o AUTOSERVICIO (CU15 A1).
     */
    public function programar(array $datos, Usuario $usuario, string $origen): Cita
    {
        try {
            // RN13 / RF06: la disponibilidad se comprueba otra vez dentro de la misma transacción que guarda.
            return DB::transaction(function () use ($datos, $usuario, $origen) {
                // a) La ficha queda bloqueada (FOR UPDATE) hasta el final de la transacción.
                $ficha = Ficha::whereKey($datos["ficha_id"])->lockForUpdate()->first();
                if ($ficha === null) {
                    throw new ReglaNegocioException("La ficha elegida no existe. Elija otro horario disponible.");
                }
                $ficha->load(["horario.consultorio", "horario.medicoEspecialidad.medico.persona", "horario.medicoEspecialidad.especialidad"]);

                $paciente = Paciente::with("persona")->find($datos["paciente_id"]);
                if ($paciente === null) {
                    throw new ReglaNegocioException("El paciente elegido no existe.");
                }

                // a) R-SOLAPE / RN17: serializa las reservas del mismo paciente.
                DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["citas.paciente." . $paciente->id]);

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
                    "codigo" => $codigo,
                    "origen" => $origen,
                    "canal" => "WEB",
                    "motivo" => ($datos["motivo"] ?? null) ?: null,
                    "estado" => "PROGRAMADA",
                    "codigo_autorizacion_seguro" => $seguro ? (($datos["codigo_autorizacion_seguro"] ?? null) ?: null) : null,
                    // CU15 A4: la atención virtual lleva su enlace de videollamada.
                    "enlace_virtual" => $ficha->horario->modalidad === "VIRTUAL" ? "https://meet.jit.si/ClinicaVidaSalud-" . $codigo : null,
                    "fecha_registro" => now(),
                ]);

                // d) CU15 paso 9: primer registro del historial.
                HistorialCita::create([
                    "cita_id" => $cita->id,
                    "usuario_realiza_id" => $usuario->id,
                    "ficha_nueva_id" => $ficha->id,
                    "estado_anterior" => null,
                    "estado_nuevo" => "PROGRAMADA",
                    "canal" => "WEB",
                    "motivo" => $origen === self::ORIGEN_AUTOSERVICIO ? "Reserva en línea del paciente" : "Programada por recepción",
                ]);

                // e) CU15 paso 9: avisos pendientes (el envío es del Ciclo 2).
                $this->crearNotificaciones($cita, $paciente, $ficha, $usuario, $inicio);

                // f) Bitácora con los datos nuevos.
                Bitacora::registrar("PROGRAMAR_CITA", "citas", $usuario->id, true, "citas", $cita->id, "WEB", null, $this->foto($cita, $ficha, $paciente));

                return $cita;
            });
        } catch (QueryException $e) {
            // RF07: otro usuario guardó una cita en la misma ficha en el mismo instante.
            if (($e->errorInfo[0] ?? null) === "23505" && str_contains($e->getMessage(), "ux_citas_ficha_activa")) {
                throw new ReglaNegocioException("La ficha acaba de ser tomada por otro usuario. Elija otro horario disponible.");
            }

            throw $e;
        }
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

    // RN15, RN16 y RN17: reglas del paciente en la FECHA DE LA CITA.
    private function validarPacienteEnLaFecha(Paciente $paciente, Ficha $ficha, Carbon $inicio): void
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
            ->whereIn("citas.estado", self::ESTADOS_VIGENTES)
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

    // CU15 paso 9: CITA_PROGRAMADA y RECORDATORIO_CITA PENDIENTES al paciente (o a su responsable
    // legal verificado si es menor) y CITA_PROGRAMADA al médico por SISTEMA.
    private function crearNotificaciones(Cita $cita, Paciente $paciente, Ficha $ficha, Usuario $usuario, Carbon $inicio): void
    {
        $horario = $ficha->horario;
        $medicoPersona = $horario->medicoEspecialidad->medico->persona;
        $especialidad = $horario->medicoEspecialidad->especialidad->nombre;
        $cuando = $inicio->format("d/m/Y") . " de " . substr($ficha->hora_inicio, 0, 5) . " a " . substr($ficha->hora_fin, 0, 5);
        $lugar = $horario->modalidad === "VIRTUAL"
            ? "en línea (enlace: {$cita->enlace_virtual})"
            : "en {$horario->consultorio->nombre} ({$horario->consultorio->ubicacion})";
        $detalle = "{$paciente->persona->nombre_completo} con " . self::tituloMedico($medicoPersona) . " ({$especialidad}) el {$cuando} {$lugar}";

        // Destinatario: el paciente o, si es menor en la fecha de la cita, su responsable legal verificado
        // (si reserva un responsable, él mismo).
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

        Notificacion::create($base + [
            "persona_id" => $destinatario->id,
            "tipo" => "CITA_PROGRAMADA",
            "canal" => $canal,
            "titulo" => "Cita {$cita->codigo} programada",
            "mensaje" => "Se programó la cita {$cita->codigo} de {$detalle}.",
            "programada_para" => now(),
        ]);

        // Recordatorio: horas_anticipacion de la cuenta (24 si no tiene), solo si ese momento es futuro.
        $recordar = $inicio->copy()->subHours($cuenta?->horas_anticipacion ?? 24);
        if ($recordar->isFuture()) {
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
            "tipo" => "CITA_PROGRAMADA",
            "canal" => "SISTEMA",
            "titulo" => "Nueva cita {$cita->codigo}",
            "mensaje" => "Nueva cita {$cita->codigo}: {$paciente->persona->nombre_completo} el {$cuando}.",
            "programada_para" => now(),
        ]);
    }

    // Datos de la cita para la bitácora.
    private function foto(Cita $cita, Ficha $ficha, Paciente $paciente): array
    {
        $asignacion = $ficha->horario->medicoEspecialidad;

        return [
            "codigo" => $cita->codigo,
            "paciente" => $paciente->codigo,
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
        ];
    }
}
