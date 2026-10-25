<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Ficha;
use App\Models\MedicoEspecialidad;
use App\Models\Paciente;
use App\Models\PacienteSeguro;
use App\Models\VinculoPaciente;
use App\Services\CitaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * CU15 Programar cita (RF06, RF07). Recepción con citas.programar (origen RECEPCION) o el paciente
 * con citas.autoservicio (A1, origen AUTOSERVICIO). Las reglas de negocio están en CitaService.
 * Reprogramar (CU16) y cancelar (CU17) se implementan en sus propios bloques.
 */
class CitaController extends Controller
{
    public function __construct(private CitaService $citas)
    {
    }

    // Lista de citas de una fecha (por defecto hoy) con filtro por estado, ordenadas por hora.
    public function index(Request $request)
    {
        abort_unless(Gate::any(["citas.programar", "agenda.consultar"]), 403);

        $fecha = $this->fechaValida($request->query("fecha")) ?? today();
        $estado = in_array($request->query("estado"), Cita::ESTADOS, true) ? $request->query("estado") : null;

        $citas = $this->consultaCitas()
            ->where("fichas.fecha", $fecha->toDateString())
            ->when($estado, fn ($consulta) => $consulta->where("citas.estado", $estado))
            ->orderBy("fichas.hora_inicio")
            ->orderBy("citas.codigo")
            ->paginate(30)
            ->withQueryString();

        return view("citas.index", [
            "citas" => $citas,
            "fecha" => $fecha,
            "estado" => $estado,
            "estados" => Cita::ESTADOS,
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
        $datos = $request->validate($this->reglas(), $this->mensajes());

        try {
            $cita = $this->citas->programar($datos, $request->user(), $modo);
        } catch (ReglaNegocioException $e) {
            // CU15 paso 8: vuelve al formulario con el mensaje y lo elegido (las fichas se recargan).
            return back()->withErrors(["cita" => $e->getMessage()])->withInput();
        }

        // CU15 paso 10.
        return redirect()->route("citas.mostrar", $cita->id)->with("exito", "Cita {$cita->codigo} registrada.");
    }

    // RF06: detalle de la cita y su historial.
    public function show(Request $request, $id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $cita = Cita::with([
            "paciente.persona", "ficha.horario.consultorio", "ficha.horario.medicoEspecialidad.medico.persona",
            "ficha.horario.medicoEspecialidad.especialidad", "seguroPaciente.aseguradora", "usuarioRegistra.persona",
            "historiales" => fn ($consulta) => $consulta->orderBy("fecha_hora")->orderBy("id"),
            "historiales.usuario", "historiales.fichaAnterior", "historiales.fichaNueva",
        ])->findOrFail($id);

        $this->autorizarVer($request, $cita);

        return view("citas.show", ["cita" => $cita]);
    }

    // CU16 Reprogramar cita (pendiente: se implementa en su bloque).
    public function update(Request $request)
    {
        //
    }

    // CU17 Cancelar cita (pendiente: se implementa en su bloque).
    public function delete(Request $request)
    {
        //
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

        $fichas = $this->citas->fichasDisponibles((int) $datos["medico_id"], (int) $datos["especialidad_id"], Carbon::parse($datos["fecha"]));

        return response()->json($fichas->map(fn ($ficha) => [
            "id" => $ficha->id,
            "hora_inicio" => substr($ficha->hora_inicio, 0, 5),
            "hora_fin" => substr($ficha->hora_fin, 0, 5),
            "modalidad" => $ficha->modalidad,
            "consultorio" => $ficha->consultorio,
            "ubicacion" => $ficha->ubicacion,
        ])->values());
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
        $persona = $request->user()->persona;
        $propio = $persona?->paciente;

        $menores = Paciente::with("persona")
            ->where("estado", "ACTIVO")
            ->whereIn("id", VinculoPaciente::where("persona_id", $persona?->id)
                ->where("estado", "ACTIVO")
                ->where("es_responsable_legal", true)
                ->whereNotNull("fecha_verificacion")
                ->select("paciente_id"))
            ->get()
            ->filter(fn ($paciente) => $paciente->esMenor());

        return collect($propio && $propio->estado === "ACTIVO" ? [$propio->load("persona")] : [])->concat($menores);
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
                ])->values()->all(),
            ])->values()->all();
    }

    // RF06: quién ve una cita. citas.programar o citas.historial_consultar (el médico solo sus
    // citas, como en CU11 A1), o el paciente dueño / su responsable legal verificado.
    private function autorizarVer(Request $request, Cita $cita): void
    {
        $usuario = $request->user();
        $persona = $usuario->persona;

        if (Gate::allows("citas.programar")) {
            return;
        }
        if (Gate::allows("citas.historial_consultar")) {
            $medico = $persona?->medico;
            abort_if($medico && $cita->medico()?->id !== $medico->id, 403);

            return;
        }

        $esDueno = $cita->paciente->persona_id === $persona?->id;
        $esResponsable = VinculoPaciente::where("paciente_id", $cita->paciente_id)
            ->where("persona_id", $persona?->id)
            ->where("estado", "ACTIVO")
            ->where("es_responsable_legal", true)
            ->whereNotNull("fecha_verificacion")
            ->exists();
        abort_unless($esDueno || $esResponsable, 403);
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
            ->select("citas.id", "citas.codigo", "citas.estado", "fichas.fecha", "fichas.hora_inicio", "fichas.hora_fin",
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

    // CU15 paso 6: formato de los datos del formulario.
    private function reglas(): array
    {
        return [
            "paciente_id" => ["required", "integer", "exists:pacientes,id"],
            "especialidad_id" => ["required", "integer", "exists:especialidades,id"],
            "medico_id" => ["required", "integer", "exists:medicos,id"],
            "fecha" => ["required", "date_format:Y-m-d"],
            "ficha_id" => ["required", "integer", "exists:fichas,id"],
            "motivo" => ["nullable", "string", "max:500"],
            "seguro_paciente_id" => ["nullable", "integer", "exists:seguros_paciente,id"],
            "codigo_autorizacion_seguro" => ["nullable", "string", "max:100"],
        ];
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
        ];
    }
}
