<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Ficha;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\MedicoEspecialidad;
use App\Models\Paciente;
use App\Models\PacienteSeguro;
use App\Services\AgendaService;
use App\Services\CitaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;

/**
 * CU15 Programar cita (RF06, RF07), CU16 Reprogramar cita (RF08), CU17 Cancelar cita (RF09), CU18
 * Registrar asistencia (RF10) y CU19 Consultar agenda médica (RF11). Recepción con citas.programar /
 * reprogramar / cancelar / confirmar / registrar_asistencia, el médico con citas.registrar_asistencia
 * y agenda.propia (solo sus citas) y el paciente con citas.autoservicio (sus citas o las de los menores
 * a su cargo). Las reglas de negocio están en CitaService; aquí se valida el formato y se responde.
 */
class CitaController extends Controller
{
    public function __construct(private CitaService $citas, private AgendaService $agenda)
    {
    }

    /**
     * CU19 Consultar agenda médica (RF11, §3.2, §3.3): las fichas de un médico en una fecha, con sus
     * citas (también las canceladas), los totales por estado y los botones de CU18.
     * agenda.consultar: cualquier médico; agenda.propia: solo la suya (se ignora ?medico_id=).
     */
    public function agenda(Request $request)
    {
        $usuario = $request->user();
        $propio = $usuario->persona?->medico;
        $todos = Gate::allows("agenda.consultar");
        // RN12: sin agenda.consultar ni agenda.propia (con perfil de médico), 403.
        abort_unless($todos || (Gate::allows("agenda.propia") && $propio !== null), 403);

        // CU19 pasos 1 y 3: hoy por defecto; el médico ve directamente la suya.
        $filtros = [
            "medico_id" => $todos ? $request->query("medico_id", $propio?->id) : $propio->id,
            "fecha" => $request->query("fecha", today()->toDateString()),
            "estado" => $request->query("estado") ?: null,
        ];
        $validador = Validator::make($filtros, $this->reglas("agenda"), $this->mensajes());
        $errores = $validador->fails() ? $validador->errors() : new MessageBag();
        if ($errores->has("fecha")) {
            $filtros["fecha"] = today()->toDateString();
        }
        if ($errores->has("estado")) {
            $filtros["estado"] = null;
        }
        $fecha = Carbon::parse($filtros["fecha"]);
        $medico = $errores->has("medico_id") || empty($filtros["medico_id"]) ? null : Medico::with("persona")->find($filtros["medico_id"]);

        $datos = [
            "todos" => $todos,
            "filtros" => $filtros,
            "errores" => $errores,
            "fecha" => $fecha,
            "medico" => $medico,
            "estados" => Cita::ESTADOS,
            // CU19 paso 2: médicos ACTIVOS e INACTIVOS (las citas históricas se consultan igual).
            "medicos" => $todos ? Medico::join("personas", "personas.id", "=", "medicos.persona_id")
                ->orderBy("personas.apellido_paterno")->orderBy("personas.nombres")
                ->get(["medicos.id", "medicos.codigo", "medicos.estado", "personas.nombres", "personas.apellido_paterno", "personas.sexo"]) : collect(),
            "filas" => [],
            "totales" => [],
            "mensaje" => null,
            "diasAtencion" => [],
        ];
        if ($medico === null) {
            return view("citas.agenda", $datos);
        }

        // CU19 paso 4: fichas del día de los horarios del médico vigentes ese día (y las que tienen citas,
        // aunque el horario se haya cerrado después), con su especialidad y el motivo del bloqueo.
        $dia = $fecha->toDateString();
        $fichas = Ficha::join("horarios_atencion as h", "h.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades as me", "me.id", "=", "h.medico_especialidad_id")
            ->join("especialidades", "especialidades.id", "=", "me.especialidad_id")
            ->where("me.medico_id", $medico->id)
            ->where("fichas.fecha", $dia)
            ->where(fn ($consulta) => $consulta
                ->where(fn ($vigente) => $vigente->where("h.estado", "ACTIVO")->where("h.vigente_desde", "<=", $dia)
                    ->where(fn ($hasta) => $hasta->whereNull("h.vigente_hasta")->orWhere("h.vigente_hasta", ">=", $dia)))
                ->orWhereExists(fn ($cita) => $cita->selectRaw("1")->from("citas")->whereColumn("citas.ficha_id", "fichas.id")))
            ->select("fichas.*", "h.modalidad", "especialidades.nombre as especialidad")
            ->selectRaw(AgendaService::SQL_MOTIVO_BLOQUEO . " as motivo_bloqueo")
            ->orderBy("fichas.hora_inicio")
            ->get();

        // CU19 paso 4: todas las citas de esas fichas (una ficha puede tener varias canceladas y una activa).
        $citas = Cita::with(["paciente.persona", "ficha.horario.medicoEspecialidad.medico"])
            ->whereIn("ficha_id", $fichas->pluck("id"))
            ->orderBy("fecha_registro")
            ->orderBy("id")
            ->get()
            ->groupBy("ficha_id");

        // CU19 A1 (RF11): el motivo solo lo ven el médico de la cita y recepción (citas.programar).
        $verMotivo = Gate::allows("citas.programar") || $propio?->id === $medico->id;

        $filas = [];
        $totales = array_fill_keys(array_merge(Cita::ESTADOS, ["LIBRE", "BLOQUEADA"]), 0);
        foreach ($fichas as $ficha) {
            $deLaFicha = $citas->get($ficha->id, collect());
            foreach ($deLaFicha as $cita) {
                $totales[$cita->estado]++;
                // CU19 paso 3: con un estado elegido, solo las citas en ese estado.
                if ($filtros["estado"] === null || $cita->estado === $filtros["estado"]) {
                    $filas[] = [
                        "ficha" => $ficha,
                        "cita" => $cita,
                        "tipo" => $cita->estado,
                        "motivo" => $verMotivo ? ($cita->motivo ?: "—") : "Reservado",
                        // CU19 paso 6 / CU18: solo los botones válidos.
                        "acciones" => $cita->accionesPermitidas($usuario),
                    ];
                }
            }

            // Sin cita activa: la ficha está libre o bloqueada (no se lista con un filtro de estado).
            if (! $deLaFicha->contains(fn ($cita) => $cita->estado !== "CANCELADA")) {
                $tipo = $ficha->estado === "BLOQUEADA" ? "BLOQUEADA" : "LIBRE";
                $totales[$tipo]++;
                if ($filtros["estado"] === null) {
                    $filas[] = ["ficha" => $ficha, "cita" => null, "tipo" => $tipo, "motivo" => null, "acciones" => []];
                }
            }
        }

        // CU19 excepción: sin fichas ese día, ¿atiende ese día de la semana? Si no, los días en que sí atiende.
        if ($fichas->isEmpty()) {
            $atiendeEseDia = HorarioAtencion::join("medicos_especialidades as me", "me.id", "=", "horarios_atencion.medico_especialidad_id")
                ->where("me.medico_id", $medico->id)
                ->where("horarios_atencion.estado", "ACTIVO")
                ->where("horarios_atencion.dia_semana", $fecha->dayOfWeekIso)
                ->where("horarios_atencion.vigente_desde", "<=", $dia)
                ->where(fn ($hasta) => $hasta->whereNull("horarios_atencion.vigente_hasta")->orWhere("horarios_atencion.vigente_hasta", ">=", $dia))
                ->exists();
            $datos["mensaje"] = $atiendeEseDia ? "No hay fichas generadas para la fecha seleccionada." : "El médico no atiende en la fecha seleccionada.";
            $datos["diasAtencion"] = $this->agenda->horariosVigentes($medico->id)->groupBy("especialidad")
                ->map(fn ($horarios) => AgendaService::textoAtencion($horarios))->all();
        }

        return view("citas.agenda", array_merge($datos, ["filas" => $filas, "totales" => $totales]));
    }

    // Lista de citas de una fecha (por defecto hoy) con filtro por estado, ordenadas por hora.
    // CU18 paso 1: cada cita muestra solo los botones válidos (accionesPermitidas).
    // CU18 A2: el médico sin agenda.consultar ve solo SUS citas («Mis citas de hoy», ?mias=1).
    public function index(Request $request)
    {
        $usuario = $request->user();
        $medico = $usuario->persona?->medico;
        $soloMias = ! Gate::any(["citas.programar", "agenda.consultar"]);
        if ($soloMias) {
            abort_unless(Gate::allows("citas.registrar_asistencia") && $medico !== null, 403);
        }
        $soloMias = $soloMias || ($request->boolean("mias") && $medico !== null);

        $fecha = $this->fechaValida($request->query("fecha")) ?? today();
        $estado = in_array($request->query("estado"), Cita::ESTADOS, true) ? $request->query("estado") : null;

        $citas = $this->consultaCitas()
            ->where("fichas.fecha", $fecha->toDateString())
            ->when($estado, fn ($consulta) => $consulta->where("citas.estado", $estado))
            ->when($soloMias, fn ($consulta) => $consulta->where("medicos.id", $medico->id))
            ->orderBy("fichas.hora_inicio")
            ->orderBy("citas.codigo")
            ->paginate(30)
            ->withQueryString();

        // CU18 paso 2: acciones válidas de cada cita según su estado, la hora y los permisos.
        $citas->getCollection()->load(["paciente", "ficha.horario.medicoEspecialidad.medico"]);
        $acciones = $citas->getCollection()->mapWithKeys(fn ($cita) => [$cita->id => $cita->accionesPermitidas($usuario)]);

        return view("citas.index", [
            "citas" => $citas,
            "acciones" => $acciones,
            "fecha" => $fecha,
            "estado" => $estado,
            "estados" => Cita::ESTADOS,
            "soloMias" => $soloMias,
        ]);
    }

    // CU15 pasos 1 a 5 (y A1): formulario con buscador de paciente, especialidad, médico, fecha,
    // fichas disponibles, motivo, seguro y resumen antes de confirmar.
    public function create(Request $request)
    {
        $modo = $this->modo();
        $usuario = $request->user();

        // CU15 paso 1: paciente ya elegido (?paciente_id= desde la ficha del paciente o al volver con errores).
        // A1: el paciente elige entre «Para mí» y los menores a su cargo.
        $opciones = [];
        $inicialPaciente = null;
        if ($modo === CitaService::ORIGEN_AUTOSERVICIO) {
            $opciones = $this->pacientesDelUsuario($request)->map(fn ($paciente) => $this->datosPaciente($paciente))->values()->all();
        } else {
            $id = old("paciente_id", $request->query("paciente_id"));
            $paciente = ctype_digit((string) $id) ? Paciente::with("persona")->where("estado", "ACTIVO")->find($id) : null;
            $inicialPaciente = $paciente ? $this->datosPaciente($paciente) : null;
        }

        // CU14 paso 6: ficha preseleccionada desde la consulta de disponibilidad.
        $ficha = ctype_digit((string) $request->query("ficha_id"))
            ? Ficha::with("horario.medicoEspecialidad")->find($request->query("ficha_id"))
            : null;

        // Lo elegido antes (al volver con un error se conserva todo).
        $inicial = [
            "paciente_id" => old("paciente_id", $inicialPaciente["id"] ?? ($opciones[0]["id"] ?? null)),
            "especialidad_id" => old("especialidad_id", $ficha?->horario->medicoEspecialidad->especialidad_id),
            "medico_id" => old("medico_id", $ficha?->horario->medicoEspecialidad->medico_id),
            "fecha" => old("fecha", $ficha ? Carbon::parse($ficha->fecha)->toDateString() : today()->toDateString()),
            "ficha_id" => old("ficha_id", $ficha?->id),
            "motivo" => old("motivo", ""),
            "seguro_paciente_id" => old("seguro_paciente_id", ""),
            "codigo_autorizacion_seguro" => old("codigo_autorizacion_seguro", ""),
        ];

        return view("citas.create", [
            "modo" => $modo,
            "opciones" => $opciones,
            "inicialPaciente" => $inicialPaciente,
            "inicial" => $inicial,
            "especialidades" => $this->especialidadesConMedicos(),
            // A1: sin correo verificado no se puede confirmar.
            "correoVerificado" => $modo === CitaService::ORIGEN_RECEPCION || $usuario->correo_verificado_en !== null,
        ]);
    }

    // CU15 pasos 6 a 10: validar el formato y programar con CitaService.
    public function store(Request $request)
    {
        $modo = $this->modo();

        // CU15 paso 6: formato (las reglas de negocio las revisa CitaService dentro de la transacción).
        $datos = $request->validate($this->reglas("programar"), $this->mensajes());

        try {
            $cita = $this->citas->programar($datos, $request->user(), $modo);
        } catch (ReglaNegocioException $e) {
            // CU15 paso 8: vuelve al formulario con el mensaje y lo elegido (las fichas se recargan).
            return back()->withErrors(["cita" => $e->getMessage()])->withInput();
        }

        // CU15 paso 10.
        return redirect()->route("citas.mostrar", $cita->id)->with("exito", "Cita {$cita->codigo} registrada.");
    }

    // RF06: detalle de la cita y su historial. CU16 pasos 1–4, CU17 pasos 2–3 y CU18 paso 2: los
    // botones y formularios de las acciones válidas (accionesPermitidas).
    public function show(Request $request, $id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $cita = Cita::with([
            "paciente.persona", "ficha.horario.consultorio", "ficha.horario.medicoEspecialidad.medico.persona",
            "ficha.horario.medicoEspecialidad.especialidad", "seguroPaciente.aseguradora", "usuarioRegistra.persona",
            "historiales" => fn ($consulta) => $consulta->orderBy("fecha_hora")->orderBy("id"),
            "historiales.usuario", "historiales.fichaAnterior", "historiales.fichaNueva",
            // CU16 A1: «Reemplaza a» / «Reemplazada por».
            "citaReemplazada", "reemplazadaPor",
        ])->findOrFail($id);

        $this->autorizarVer($request, $cita);

        $usuario = $request->user();
        $acciones = $cita->accionesPermitidas($usuario);
        $asignacion = $cita->ficha->horario->medicoEspecialidad;

        // CU16 A1: médicos ACTIVOS de la misma especialidad, sin el actual.
        $medicosReasignar = [];
        if (in_array("reasignar", $acciones, true)) {
            $especialidad = collect($this->especialidadesConMedicos())->firstWhere("id", $asignacion->especialidad_id);
            $medicosReasignar = collect($especialidad["medicos"] ?? [])->reject(fn ($m) => $m["id"] === $asignacion->medico_id)->values()->all();
        }

        // Avisos cuando la acción existe pero hoy no se puede (CU16 A2 / CU17 A1 y RN18).
        $avisos = [];
        if (in_array($cita->estado, Cita::ESTADOS_VIGENTES, true) && ! $cita->comenzo()) {
            $porLaWeb = ! Gate::any(["citas.reprogramar", "citas.cancelar"]) && Gate::allows("citas.autoservicio");
            if ($porLaWeb && ! $cita->aTiempoParaLaWeb()) {
                $avisos[] = "Las citas solo se pueden reprogramar o cancelar por la web con al menos "
                    . Cita::parametro("horas_minimas_cambio_web") . " horas de anticipación. Comuníquese con recepción.";
            } elseif (Gate::any(["citas.reprogramar", "citas.autoservicio"]) && $cita->reprogramacionesRestantes() === 0) {
                $avisos[] = "La cita ya se reprogramó " . Cita::parametro("max_reprogramaciones") . " veces. Cancélela y registre una cita nueva.";
            }
        }

        return view("citas.show", [
            "cita" => $cita,
            "acciones" => $acciones,
            "avisos" => $avisos,
            "restantes" => $cita->reprogramacionesRestantes(),
            "maximo" => Cita::parametro("max_reprogramaciones"),
            "medicosReasignar" => $medicosReasignar,
            // CU16 mejora a: días y horas en que atiende el médico actual en esta especialidad.
            "atiende" => "Atiende: " . (AgendaService::textoAtencion($this->agenda->horariosVigentes($asignacion->medico_id)
                ->where("especialidad_id", $asignacion->especialidad_id)) ?: "sin horarios vigentes"),
        ]);
    }

    // CU16 pasos 5 a 7: reprogramar (nueva ficha del mismo médico). Recepción con citas.reprogramar
    // o, por la web, el paciente dueño o su responsable (A2, citas.autoservicio).
    public function update(Request $request)
    {
        abort_unless(Gate::any(["citas.reprogramar", "citas.autoservicio"]), 403);

        $datos = $request->validate($this->reglas("reprogramar"), $this->mensajes());
        $cita = Cita::findOrFail($datos["id"]);

        try {
            $cita = $this->citas->reprogramar($cita, (int) $datos["ficha_id"], $request->user(), $datos["motivo"] ?? null, ! Gate::allows("citas.reprogramar"));
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["cita" => $e->getMessage()])->withInput();
        }

        return redirect()->route("citas.mostrar", $cita->id)
            ->with("exito", "Cita {$cita->codigo} reprogramada al " . $cita->inicio()->format("d/m/Y H:i") . ".");
    }

    // CU16 A1: reasignar a otro médico de la misma especialidad (solo citas.reprogramar).
    public function reasignar(Request $request)
    {
        abort_unless(Gate::allows("citas.reprogramar"), 403);

        $datos = $request->validate($this->reglas("reasignar"), $this->mensajes());
        $original = Cita::findOrFail($datos["id"]);

        try {
            $nueva = $this->citas->reasignar($original, (int) $datos["ficha_id"], $request->user(), $datos["motivo"] ?? null);
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["cita" => $e->getMessage()])->withInput();
        }

        return redirect()->route("citas.mostrar", $nueva->id)
            ->with("exito", "Cita reasignada: {$nueva->codigo} reemplaza a {$original->codigo}.");
    }

    // CU17 pasos 4 a 7: cancelar con motivo (no se elimina el registro). Recepción con citas.cancelar
    // o, por la web, el paciente dueño o su responsable (A1, citas.autoservicio).
    public function delete(Request $request)
    {
        abort_unless(Gate::any(["citas.cancelar", "citas.autoservicio"]), 403);

        $datos = $request->validate($this->reglas("cancelar"), $this->mensajes());
        $cita = Cita::findOrFail($datos["id"]);

        try {
            $cita = $this->citas->cancelar($cita, $datos["motivo_cancelacion"], $request->user(), ! Gate::allows("citas.cancelar"));
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["cita" => $e->getMessage()])->withInput();
        }

        return back()->with("exito", "Cita {$cita->codigo} cancelada. El horario quedó disponible.");
    }

    // CU18 pasos 3 a 5 y A1: confirmar, atendida o inasistencia. El permiso de cada transición
    // (citas.confirmar / citas.registrar_asistencia, A2 el médico solo sus citas) lo revisa CitaService.
    public function cambiarEstado(Request $request)
    {
        abort_unless(Gate::any(["citas.confirmar", "citas.registrar_asistencia"]), 403);

        $datos = $request->validate($this->reglas("estado"), $this->mensajes());
        $cita = Cita::findOrFail($datos["id"]);

        try {
            $cita = $this->citas->cambiarEstado($cita, $datos["estado"], $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["cita" => $e->getMessage()]);
        }

        return back()->with("exito", "Cita {$cita->codigo}: " . mb_strtolower($cita->estado) . ".");
    }

    // CU15 paso 3 (JSON): SOLO las fichas disponibles del médico en la especialidad y la fecha (RF06).
    public function fichasDisponibles(Request $request)
    {
        $this->modo();

        $datos = $request->validate([
            "medico_id" => ["required", "integer"],
            "especialidad_id" => ["required", "integer"],
            "fecha" => ["required", "date_format:Y-m-d", "after_or_equal:today"],
        ], [
            "medico_id.required" => "Elija el médico.",
            "especialidad_id.required" => "Elija la especialidad.",
            "fecha.required" => "Elija la fecha.",
            "fecha.date_format" => "La fecha no es válida.",
            "fecha.after_or_equal" => "La fecha no puede ser anterior a hoy.",
        ]);

        $fecha = Carbon::parse($datos["fecha"]);
        $fichas = $this->citas->fichasDisponibles((int) $datos["medico_id"], (int) $datos["especialidad_id"], $fecha);

        // CU15 / CU16 mejora b: sin fichas ese día, el próximo día disponible (hasta 60 días después).
        $proxima = $fichas->isEmpty() ? $this->citas->proximaFechaDisponible((int) $datos["medico_id"], (int) $datos["especialidad_id"], $fecha) : null;

        return response()->json([
            "fichas" => $fichas->map(fn ($ficha) => [
                "id" => $ficha->id,
                "hora_inicio" => substr($ficha->hora_inicio, 0, 5),
                "hora_fin" => substr($ficha->hora_fin, 0, 5),
                "modalidad" => $ficha->modalidad,
                "consultorio" => $ficha->consultorio,
                "ubicacion" => $ficha->ubicacion,
            ])->values(),
            "proxima" => $proxima ? [
                "fecha" => $proxima->toDateString(),
                "texto" => mb_strtolower(AgendaService::DIAS[$proxima->dayOfWeekIso]) . " " . $proxima->format("d/m/Y"),
            ] : null,
        ]);
    }

    // CU15 paso 1 (JSON): buscar pacientes ACTIVOS con el mismo criterio que CU11.
    public function buscarPacientes(Request $request)
    {
        abort_unless(Gate::allows("citas.programar"), 403);

        $texto = trim((string) $request->query("q", ""));
        if (mb_strlen($texto) < 2) {
            return response()->json([]);
        }

        $pacientes = Paciente::buscar($texto)->where("pacientes.estado", "ACTIVO")->limit(10)->get();

        return response()->json($pacientes->map(fn ($paciente) => $this->datosPaciente($paciente))->values());
    }

    // CU15: origen según el permiso (citas.programar = RECEPCION, citas.autoservicio = AUTOSERVICIO); si no, 403.
    private function modo(): string
    {
        if (Gate::allows("citas.programar")) {
            return CitaService::ORIGEN_RECEPCION;
        }
        abort_unless(Gate::allows("citas.autoservicio"), 403);

        return CitaService::ORIGEN_AUTOSERVICIO;
    }

    // CU15 A1 / R-TUTOR: el propio paciente (si está ACTIVO) y los menores de los que es responsable legal verificado.
    private function pacientesDelUsuario(Request $request)
    {
        // Solo los ACTIVOS reciben citas nuevas (RN03).
        return Paciente::aCargoDe($request->user()->persona)->where("estado", "ACTIVO")->values();
    }

    // Datos de un paciente para el formulario: edad y sexo filtran las especialidades (RN15) y
    // los seguros ACTIVOS y verificados llenan el select del paso 4 (RN20).
    private function datosPaciente(Paciente $paciente): array
    {
        $persona = $paciente->persona;
        $seguros = PacienteSeguro::with("aseguradora")
            ->where("paciente_id", $paciente->id)
            ->where("estado", "ACTIVO")
            ->whereNotNull("fecha_verificacion")
            ->where("vigente_hasta", ">=", today()->toDateString())
            ->orderBy("id")
            ->get();

        return [
            "id" => $paciente->id,
            "codigo" => $paciente->codigo,
            "nombre" => $persona->nombre_completo,
            "documento" => $persona->numero_documento ? $persona->numero_documento . ($persona->complemento ? "-" . $persona->complemento : "") : "Sin documento",
            "edad" => $persona->edad,
            "sexo" => $persona->sexo,
            "esPropio" => $paciente->persona_id === request()->user()->persona_id,
            "seguros" => $seguros->map(fn ($seguro) => [
                "id" => $seguro->id,
                "texto" => "{$seguro->aseguradora->nombre} · póliza {$seguro->numero_poliza} (vigente hasta " . $seguro->vigente_hasta->format("d/m/Y") . ")",
            ])->values()->all(),
        ];
    }

    // CU15 paso 2: especialidades ACTIVAS con sus límites (RN15) y los médicos ACTIVOS que las ejercen.
    private function especialidadesConMedicos(): array
    {
        $medicos = MedicoEspecialidad::join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->where("medicos_especialidades.estado", "ACTIVO")
            ->where("medicos.estado", "ACTIVO")
            ->orderBy("personas.apellido_paterno")
            ->get(["medicos_especialidades.especialidad_id", "medicos.id", "personas.nombres", "personas.apellido_paterno", "personas.sexo"])
            ->groupBy("especialidad_id");

        // CU15 / CU16 mejora a: «Atiende: lunes y miércoles de 08:00 a 12:00» por médico y especialidad.
        $atiende = $this->agenda->horariosVigentes()
            ->groupBy(fn ($horario) => $horario->medico_id . "-" . $horario->especialidad_id)
            ->map(fn ($horarios) => "Atiende: " . AgendaService::textoAtencion($horarios));

        return Especialidad::where("estado", "ACTIVO")->orderBy("nombre")->get()
            ->map(fn ($especialidad) => [
                "id" => $especialidad->id,
                "nombre" => $especialidad->nombre,
                "edad_minima" => $especialidad->edad_minima,
                "edad_maxima" => $especialidad->edad_maxima,
                "sexo_permitido" => $especialidad->sexo_permitido,
                "medicos" => ($medicos[$especialidad->id] ?? collect())->map(fn ($m) => [
                    "id" => $m->id,
                    "nombre" => ($m->sexo === "F" ? "Dra. " : "Dr. ") . $m->nombres . " " . $m->apellido_paterno,
                    "atiende" => $atiende[$m->id . "-" . $especialidad->id] ?? "Sin horarios de atención vigentes.",
                ])->values()->all(),
            ])->values()->all();
    }

    // RF06: quién ve una cita. citas.programar o citas.historial_consultar (el médico solo sus
    // citas, como en CU11 A1), o el paciente dueño / su responsable legal verificado.
    private function autorizarVer(Request $request, Cita $cita): void
    {
        abort_unless($cita->puedeVerla($request->user()), 403);
    }

    // Citas con su ficha, paciente, médico y especialidad (para la lista).
    private function consultaCitas()
    {
        return Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->join("horarios_atencion", "horarios_atencion.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
            ->join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas as pm", "pm.id", "=", "medicos.persona_id")
            ->join("pacientes", "pacientes.id", "=", "citas.paciente_id")
            ->join("personas as pp", "pp.id", "=", "pacientes.persona_id")
            ->select("citas.id", "citas.codigo", "citas.estado", "citas.paciente_id", "citas.ficha_id", "fichas.fecha", "fichas.hora_inicio", "fichas.hora_fin",
                "pacientes.codigo as paciente_codigo", "pp.nombres as paciente_nombres", "pp.apellido_paterno as paciente_apellido",
                "especialidades.nombre as especialidad", "pm.nombres as medico_nombres", "pm.apellido_paterno as medico_apellido", "pm.sexo as medico_sexo");
    }

    // Fecha AAAA-MM-DD válida o null.
    private function fechaValida($valor): ?Carbon
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }
        try {
            return Carbon::createFromFormat("Y-m-d", $valor)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    // Formato de los datos de cada formulario: programar (CU15 paso 6), reprogramar (CU16 paso 4),
    // reasignar (CU16 A1), cancelar (CU17 paso 3) y estado (CU18). El id va oculto en el formulario.
    private function reglas(string $accion): array
    {
        return match ($accion) {
            "reprogramar", "reasignar" => [
                "id" => ["required", "integer", "exists:citas,id"],
                "fecha" => ["required", "date_format:Y-m-d"],
                "ficha_id" => ["required", "integer", "exists:fichas,id"],
                "motivo" => ["nullable", "string", "max:500"],
            ] + ($accion === "reasignar" ? ["medico_id" => ["required", "integer", "exists:medicos,id"]] : []),
            "cancelar" => [
                "id" => ["required", "integer", "exists:citas,id"],
                "motivo_cancelacion" => ["required", "string", "max:500"],
            ],
            "estado" => [
                "id" => ["required", "integer", "exists:citas,id"],
                "estado" => ["required", "in:CONFIRMADA,ATENDIDA,INASISTENCIA"],
            ],
            // CU19 paso 3: filtros de la agenda (médico, fecha y estado).
            "agenda" => [
                "medico_id" => ["nullable", "integer", "exists:medicos,id"],
                "fecha" => ["required", "date_format:Y-m-d"],
                "estado" => ["nullable", "in:" . implode(",", Cita::ESTADOS)],
            ],
            default => [
                "paciente_id" => ["required", "integer", "exists:pacientes,id"],
                "especialidad_id" => ["required", "integer", "exists:especialidades,id"],
                "medico_id" => ["required", "integer", "exists:medicos,id"],
                "fecha" => ["required", "date_format:Y-m-d"],
                "ficha_id" => ["required", "integer", "exists:fichas,id"],
                "motivo" => ["nullable", "string", "max:500"],
                "seguro_paciente_id" => ["nullable", "integer", "exists:seguros_paciente,id"],
                "codigo_autorizacion_seguro" => ["nullable", "string", "max:100"],
            ],
        };
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "paciente_id.required" => "Elija al paciente.",
            "paciente_id.integer" => "El paciente elegido no es válido.",
            "paciente_id.exists" => "El paciente elegido no existe.",
            "especialidad_id.required" => "Elija la especialidad.",
            "especialidad_id.integer" => "La especialidad elegida no es válida.",
            "especialidad_id.exists" => "La especialidad elegida no existe.",
            "medico_id.required" => "Elija el médico.",
            "medico_id.integer" => "El médico elegido no es válido.",
            "medico_id.exists" => "El médico elegido no existe.",
            "fecha.required" => "Elija la fecha.",
            "fecha.date_format" => "La fecha no es válida.",
            "ficha_id.required" => "Elija un horario disponible.",
            "ficha_id.integer" => "El horario elegido no es válido.",
            "ficha_id.exists" => "El horario elegido no existe.",
            "motivo.max" => "El motivo no puede superar los 500 caracteres.",
            "seguro_paciente_id.integer" => "El seguro elegido no es válido.",
            "seguro_paciente_id.exists" => "El seguro elegido no existe.",
            "codigo_autorizacion_seguro.max" => "El código de autorización no puede superar los 100 caracteres.",
            // CU16, CU17 y CU18.
            "id.required" => "No se indicó la cita.",
            "id.integer" => "La cita no es válida.",
            "id.exists" => "La cita no existe.",
            "motivo_cancelacion.required" => "El motivo de cancelación es obligatorio.",
            "motivo_cancelacion.max" => "El motivo de cancelación no puede superar los 500 caracteres.",
            "estado.required" => "Indique el nuevo estado.",
            "estado.in" => "El estado elegido no es válido.",
        ];
    }
}
