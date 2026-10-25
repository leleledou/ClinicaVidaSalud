<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Consultorio;
use App\Models\Ficha;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\MedicoEspecialidad;
use App\Services\AgendaService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU09 Gestionar horarios de atención (RF05, RN05, RN07, RN08). Requiere horarios.gestionar.
 * Un horario no se edita (A1): se cierra su vigencia y se registra uno nuevo. Las fichas las
 * calcula AgendaService.
 */
class HorarioAtencionController extends Controller
{
    // Campos del horario que se registran en la bitácora.
    private const CAMPOS = ["medico_especialidad_id", "consultorio_id", "dia_semana", "hora_inicio", "hora_fin",
        "duracion_ficha_min", "modalidad", "vigente_desde", "vigente_hasta", "estado"];

    public function __construct(private AgendaService $agenda)
    {
    }

    // CU09 pasos 1 a 3: selector de médico, grilla semanal de horarios vigentes e historial.
    public function index(Request $request)
    {
        // CU09 paso 1: solo médicos ACTIVOS (precondición).
        $medicos = Medico::with("persona")->where("estado", "ACTIVO")->orderBy("codigo")->get();

        $medicoId = ctype_digit((string) $request->query("medico_id")) ? (int) $request->query("medico_id") : null;
        $medico = $medicoId ? $medicos->firstWhere("id", $medicoId) : null;

        $vigentes = collect();
        $cerrados = collect();
        $especialidades = collect();

        if ($medico) {
            // CU09 paso 2: vigentes (ACTIVO y sin fin o con fin hoy o después), con las fichas futuras
            // HABILITADAS / BLOQUEADAS / OCUPADAS (ocupada = tiene una cita no CANCELADA; se calcula).
            $vigentes = $this->conConteos($this->horariosDe($medico->id))
                ->where("horarios_atencion.estado", "ACTIVO")
                ->where(fn ($consulta) => $consulta->whereNull("horarios_atencion.vigente_hasta")->orWhere("horarios_atencion.vigente_hasta", ">=", today()))
                ->orderBy("horarios_atencion.dia_semana")
                ->orderBy("horarios_atencion.hora_inicio")
                ->get()
                ->groupBy("dia_semana");

            // CU09 paso 2: historial de horarios cerrados.
            $cerrados = $this->horariosDe($medico->id)
                ->where(fn ($consulta) => $consulta->where("horarios_atencion.estado", "<>", "ACTIVO")->orWhere("horarios_atencion.vigente_hasta", "<", today()))
                ->orderByDesc("horarios_atencion.vigente_hasta")
                ->get();

            // CU09 paso 4: solo las especialidades que ejerce (asignación y especialidad ACTIVAS).
            $especialidades = MedicoEspecialidad::join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
                ->where("medicos_especialidades.medico_id", $medico->id)
                ->where("medicos_especialidades.estado", "ACTIVO")
                ->where("especialidades.estado", "ACTIVO")
                ->orderBy("especialidades.nombre")
                ->get(["medicos_especialidades.id", "especialidades.nombre", "especialidades.duracion_sugerida_min"]);
        }

        return view("horarios.index", [
            "medicos" => $medicos,
            "medico" => $medico,
            "vigentes" => $vigentes,
            "cerrados" => $cerrados,
            "especialidades" => $especialidades,
            // CU09 paso 6: solo consultorios ACTIVOS.
            "consultorios" => Consultorio::where("estado", "ACTIVO")->orderBy("nombre")->get(["id", "nombre"]),
            "dias" => AgendaService::DIAS,
            "semanas" => $this->agenda->semanasGeneracion(),
        ]);
    }

    // CU09 pasos 4 a 9: registrar un horario y generar sus fichas.
    public function store(Request $request)
    {
        // CU09 paso 7: validación de los datos.
        $datos = $request->validate($this->reglas(), $this->mensajes());
        if ($datos["modalidad"] === "VIRTUAL") {
            $datos["consultorio_id"] = null;
        }

        // Precondición y CU09 paso 7: la especialidad es del médico y está activa; el médico está ACTIVO.
        $asignacion = MedicoEspecialidad::with(["medico", "especialidad"])->find($datos["medico_especialidad_id"]);
        if (! $asignacion || $asignacion->estado !== "ACTIVO" || $asignacion->especialidad->estado !== "ACTIVO") {
            return back()->withErrors(["medico_especialidad_id" => "El médico no ejerce esa especialidad"])->withInput();
        }
        if ($asignacion->medico->estado !== "ACTIVO") {
            return back()->withErrors(["medico_especialidad_id" => "El médico está inactivo: no se le pueden registrar horarios."])->withInput();
        }

        // CU09 paso 7 / RN07: cruces, con el mensaje exacto antes de que lo rechace la base.
        if ($error = $this->errorCruce($asignacion->medico_id, $datos)) {
            return back()->withErrors($error)->withInput();
        }

        try {
            // CU09 paso 8: horario y fichas en UNA transacción.
            [$horario, $generadas, $bloqueadas] = DB::transaction(function () use ($datos, $request) {
                $horario = HorarioAtencion::create(array_merge($datos, ["estado" => "ACTIVO"]));

                $generadas = $this->agenda->generarFichas($horario, today(), today()->addWeeks($this->agenda->semanasGeneracion()));
                $bloqueadas = Ficha::where("horario_atencion_id", $horario->id)->where("estado", "BLOQUEADA")->count();

                Bitacora::registrar("CREAR_HORARIO", "agenda", $request->user()->id, true, "horarios_atencion", $horario->id, "WEB", null,
                    array_merge($horario->only(self::CAMPOS), ["fichas_generadas" => $generadas]));

                return [$horario, $generadas, $bloqueadas];
            });
        } catch (QueryException $e) {
            return $this->errorBaseDeDatos($e);
        }

        // CU09 paso 9
        return redirect()->route("horarios.listar", ["medico_id" => $asignacion->medico_id])
            ->with("exito", "Horario registrado. Se generaron {$generadas} fichas ({$bloqueadas} bloqueadas por feriados o bloqueos).");
    }

    // Ficha del horario y sus fichas desde hoy (disponible, ocupada con su cita o bloqueada con el motivo).
    public function show($id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $horario = HorarioAtencion::with(["medicoEspecialidad.medico.persona", "medicoEspecialidad.especialidad", "consultorio"])->findOrFail($id);

        $fichas = Ficha::where("horario_atencion_id", $horario->id)
            ->where("fecha", ">=", today())
            // Ocupada = tiene una cita no CANCELADA (ux_citas_ficha_activa: como máximo una).
            ->select("fichas.*")
            ->selectRaw("(SELECT c.codigo FROM citas c WHERE c.ficha_id = fichas.id AND c.estado <> 'CANCELADA' LIMIT 1) AS cita_codigo")
            ->orderBy("fecha")
            ->orderBy("hora_inicio")
            ->paginate(50)
            ->withQueryString();

        // Motivo de las bloqueadas: el bloqueo de agenda que se cruza con la ficha (si existe).
        $motivos = [];
        if ($fichas->isNotEmpty()) {
            $bloqueos = $this->agenda->bloqueosDe($horario, Carbon::parse($fichas->first()->fecha)->startOfDay(), Carbon::parse($fichas->last()->fecha)->endOfDay());
            foreach ($fichas as $ficha) {
                if ($ficha->estado === "BLOQUEADA") {
                    $inicio = Carbon::parse($ficha->fecha)->setTimeFromTimeString($ficha->hora_inicio);
                    $fin = Carbon::parse($ficha->fecha)->setTimeFromTimeString($ficha->hora_fin);
                    $motivos[$ficha->id] = $this->agenda->bloqueoQueCruza($bloqueos, $inicio, $fin)?->motivo;
                }
            }
        }

        return view("horarios.show", [
            "horario" => $horario,
            "fichas" => $fichas,
            "motivos" => $motivos,
            "dias" => AgendaService::DIAS,
            "vigente" => $this->esVigente($horario),
        ]);
    }

    // CU09 A1: cambiar un horario = cerrar su vigencia (no se edita). El id viaja oculto.
    public function delete(Request $request)
    {
        $datos = $request->validate([
            "id" => ["required", "integer", "exists:horarios_atencion,id"],
            "fecha_cierre" => ["required", "date", "after_or_equal:today"],
        ], $this->mensajes());

        $horario = HorarioAtencion::findOrFail($datos["id"]);
        $cierre = Carbon::parse($datos["fecha_cierre"])->startOfDay();

        if (! $this->esVigente($horario)) {
            return back()->withErrors(["operacion" => "El horario ya está cerrado."]);
        }
        // ck_horarios_vigencia: el fin no puede ser anterior al inicio; tampoco se alarga la vigencia.
        if ($cierre->lessThan(Carbon::parse($horario->vigente_desde))) {
            return back()->withErrors(["operacion" => "La fecha de cierre no puede ser anterior al inicio de la vigencia (" . Carbon::parse($horario->vigente_desde)->format("d/m/Y") . ")."]);
        }
        if ($horario->vigente_hasta !== null && $cierre->greaterThan(Carbon::parse($horario->vigente_hasta))) {
            return back()->withErrors(["operacion" => "El horario ya termina el " . Carbon::parse($horario->vigente_hasta)->format("d/m/Y") . ": la fecha de cierre no puede ser posterior."]);
        }

        // CU09 A1 paso 2: no se cierra si hay citas activas después de la fecha de cierre.
        $activas = Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->where("fichas.horario_atencion_id", $horario->id)
            ->where("fichas.fecha", ">", $cierre->toDateString())
            ->whereIn("citas.estado", ["PROGRAMADA", "CONFIRMADA"])
            ->count();

        if ($activas > 0) {
            return back()->withErrors(["operacion" => "Hay {$activas} cita(s) activa(s) después del {$cierre->format('d/m/Y')}. Reprográmelas antes de cerrar el horario."]);
        }

        // CU09 A1 paso 3: cerrar, eliminar las fichas libres posteriores y bloquear las que tuvieron citas canceladas.
        [$eliminadas, $bloqueadas] = DB::transaction(function () use ($horario, $cierre, $request) {
            $anteriores = $horario->only(self::CAMPOS);
            $horario->update(["vigente_hasta" => $cierre->toDateString()]);

            $posteriores = fn () => Ficha::where("horario_atencion_id", $horario->id)->where("fecha", ">", $cierre->toDateString());

            // Sin ninguna cita (ni mención en el historial de citas): se eliminan.
            $eliminadas = $posteriores()
                ->whereNotExists(fn ($consulta) => $consulta->selectRaw("1")->from("citas")->whereColumn("citas.ficha_id", "fichas.id"))
                ->whereNotExists(fn ($consulta) => $consulta->selectRaw("1")->from("historiales_cita")
                    ->where(fn ($ficha) => $ficha->whereColumn("historiales_cita.ficha_anterior_id", "fichas.id")->orWhereColumn("historiales_cita.ficha_nueva_id", "fichas.id")))
                ->delete();

            // Las que quedan solo tuvieron citas CANCELADAS (o figuran en el historial): no se pueden
            // borrar porque las referencian, quedan BLOQUEADA. Ya se verificó que no hay citas activas.
            $bloqueadas = $posteriores()->where("estado", "<>", "BLOQUEADA")
                ->whereNotExists(fn ($consulta) => $consulta->selectRaw("1")->from("citas")->whereColumn("citas.ficha_id", "fichas.id")->where("citas.estado", "<>", "CANCELADA"))
                ->update(["estado" => "BLOQUEADA"]);

            Bitacora::registrar("CERRAR_HORARIO", "agenda", $request->user()->id, true, "horarios_atencion", $horario->id, "WEB", $anteriores,
                array_merge($horario->only(self::CAMPOS), ["fichas_eliminadas" => $eliminadas, "fichas_bloqueadas" => $bloqueadas]));

            return [$eliminadas, $bloqueadas];
        });

        return back()->with("exito", "Horario cerrado al {$cierre->format('d/m/Y')}. Se eliminaron {$eliminadas} fichas libres y se bloquearon {$bloqueadas} que tenían citas canceladas. Ya puede registrar el horario nuevo.");
    }

    // CU09 A2: generar las fichas de las próximas semanas (idempotente). El id viaja oculto.
    public function generarFichas(Request $request)
    {
        $request->validate(["id" => ["required", "integer", "exists:horarios_atencion,id"]], $this->mensajes());
        $horario = HorarioAtencion::findOrFail($request->input("id"));

        // Solo para horarios vigentes.
        if (! $this->esVigente($horario)) {
            return back()->withErrors(["operacion" => "Solo se generan fichas para horarios vigentes."]);
        }

        $generadas = DB::transaction(function () use ($horario, $request) {
            $semanas = $this->agenda->semanasGeneracion();
            $generadas = $this->agenda->generarFichas($horario, today(), today()->addWeeks($semanas));

            Bitacora::registrar("GENERAR_FICHAS", "agenda", $request->user()->id, true, "horarios_atencion", $horario->id, "WEB", null,
                ["semanas" => $semanas, "fichas_generadas" => $generadas]);

            return $generadas;
        });

        return back()->with("exito", "Se generaron {$generadas} fichas nuevas");
    }

    // Horario ACTIVO cuya vigencia no terminó.
    private function esVigente(HorarioAtencion $horario): bool
    {
        return $horario->estado === "ACTIVO" && ($horario->vigente_hasta === null || ! Carbon::parse($horario->vigente_hasta)->lessThan(today()));
    }

    // Horarios de un médico con su especialidad y consultorio.
    private function horariosDe(int $medicoId)
    {
        return HorarioAtencion::join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
            ->leftJoin("consultorios", "consultorios.id", "=", "horarios_atencion.consultorio_id")
            ->where("medicos_especialidades.medico_id", $medicoId)
            ->select("horarios_atencion.*", "especialidades.nombre as especialidad", "consultorios.nombre as consultorio");
    }

    // CU09 paso 2: cantidad de fichas futuras por estado (ocupada = cita no CANCELADA).
    private function conConteos($consulta)
    {
        $ahora = now()->format("Y-m-d H:i:s");
        $futuras = "FROM fichas f WHERE f.horario_atencion_id = horarios_atencion.id AND (f.fecha + f.hora_inicio) > ?::timestamp";
        $ocupada = "EXISTS (SELECT 1 FROM citas c WHERE c.ficha_id = f.id AND c.estado <> 'CANCELADA')";

        return $consulta
            ->selectRaw("(SELECT count(*) {$futuras} AND f.estado = 'HABILITADA' AND NOT {$ocupada}) AS fichas_habilitadas", [$ahora])
            ->selectRaw("(SELECT count(*) {$futuras} AND f.estado = 'BLOQUEADA' AND NOT {$ocupada}) AS fichas_bloqueadas", [$ahora])
            ->selectRaw("(SELECT count(*) {$futuras} AND {$ocupada}) AS fichas_ocupadas", [$ahora]);
    }

    // CU09 paso 7 / RN07: mensaje del primer cruce con otro horario del médico o del consultorio.
    private function errorCruce(int $medicoId, array $datos): ?array
    {
        $argumentos = [(int) $datos["dia_semana"], $datos["hora_inicio"], $datos["hora_fin"], $datos["vigente_desde"], $datos["vigente_hasta"] ?? null];

        if ($otro = $this->agenda->haySolapeMedico($medicoId, ...$argumentos)) {
            return ["hora_inicio" => "El médico ya tiene un horario que se cruza: " . AgendaService::DIAS[$otro->dia_semana] . " "
                . substr($otro->hora_inicio, 0, 5) . "–" . substr($otro->hora_fin, 0, 5) . " ({$otro->especialidad})"];
        }

        if (! empty($datos["consultorio_id"]) && ($otro = $this->agenda->haySolapeConsultorio((int) $datos["consultorio_id"], ...$argumentos))) {
            return ["consultorio_id" => "El {$otro->consultorio} ya está ocupado el " . mb_strtolower(AgendaService::DIAS[$otro->dia_semana]) . " de "
                . substr($otro->hora_inicio, 0, 5) . " a " . substr($otro->hora_fin, 0, 5) . " por {$otro->medico_nombres} {$otro->medico_apellido} ({$otro->medico_codigo})"];
        }

        return null;
    }

    // CU09 paso 7: si aun así la base rechaza el horario (23P01 del trigger o del EXCLUDE, o un
    // CHECK 23514), se muestra el mensaje en español, nunca el error técnico.
    private function errorBaseDeDatos(QueryException $e)
    {
        $codigo = $e->errorInfo[0] ?? null;
        $texto = $e->getMessage();

        $error = match (true) {
            $codigo === "23P01" && str_contains($texto, "ex_horarios_consultorio") => ["consultorio_id" => "El consultorio ya está ocupado en ese día y horario."],
            $codigo === "23P01" => ["hora_inicio" => "El médico ya tiene un horario que se cruza con este."],
            $codigo === "23514" && str_contains($texto, "ck_horarios_horas") => ["hora_fin" => "La hora final debe ser posterior a la hora inicial"],
            $codigo === "23514" && str_contains($texto, "ck_horarios_duracion") => ["duracion_ficha_min" => "La duración no divide exactamente el rango del horario."],
            $codigo === "23514" && str_contains($texto, "ck_horarios_modalidad_consultorio") => ["consultorio_id" => "El consultorio es obligatorio para la atención presencial"],
            $codigo === "23514" && str_contains($texto, "ck_horarios_vigencia") => ["vigente_hasta" => "La vigencia no puede terminar antes de empezar."],
            $codigo === "23514" => ["hora_inicio" => "Los datos del horario no son válidos."],
            default => null,
        };

        if ($error === null) {
            throw $e;
        }

        return back()->withErrors($error)->withInput();
    }

    // CU09 pasos 4 a 7: reglas del formulario.
    private function reglas(): array
    {
        return [
            "medico_especialidad_id" => ["required", "integer"],
            "dia_semana" => ["required", "integer", "between:1,7"],
            "hora_inicio" => ["required", "date_format:H:i"],
            "hora_fin" => [
                "bail", "required", "date_format:H:i",
                // RF05: la hora final es posterior a la inicial.
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    $inicio = request()->input("hora_inicio");
                    if (is_string($inicio) && preg_match("/^\d{2}:\d{2}$/", $inicio) && $valor <= $inicio) {
                        $fallar("La hora final debe ser posterior a la hora inicial");
                    }
                },
            ],
            "duracion_ficha_min" => [
                "bail", "required", "integer", "between:5,240",
                // RN08: la duración divide exactamente el rango.
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    $inicio = request()->input("hora_inicio");
                    $fin = request()->input("hora_fin");
                    if (! is_string($inicio) || ! is_string($fin) || ! preg_match("/^\d{2}:\d{2}$/", $inicio) || ! preg_match("/^\d{2}:\d{2}$/", $fin) || $fin <= $inicio) {
                        return; // Lo informan las reglas de las horas.
                    }
                    $rango = Carbon::createFromFormat("H:i", $inicio)->diffInMinutes(Carbon::createFromFormat("H:i", $fin));
                    if ($rango % (int) $valor !== 0) {
                        $fallar("La duración de {$valor} minutos no divide exactamente el rango de {$inicio} a {$fin} ({$rango} minutos)");
                    }
                },
            ],
            "modalidad" => ["required", "in:PRESENCIAL,VIRTUAL"],
            // CU09 paso 6: consultorio ACTIVO, obligatorio en presencial y prohibido en virtual.
            "consultorio_id" => [
                "nullable", "integer", "required_if:modalidad,PRESENCIAL", "prohibited_if:modalidad,VIRTUAL",
                Rule::exists("consultorios", "id")->where("estado", "ACTIVO"),
            ],
            "vigente_desde" => ["required", "date", "after_or_equal:today"],
            "vigente_hasta" => ["nullable", "date", "after_or_equal:vigente_desde"],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el horario.",
            "id.integer" => "El horario indicado no es válido.",
            "id.exists" => "El horario indicado no existe.",
            "fecha_cierre.required" => "Indique la fecha de cierre.",
            "fecha_cierre.date" => "La fecha de cierre no es válida.",
            "fecha_cierre.after_or_equal" => "La fecha de cierre no puede ser anterior a hoy.",
            "medico_especialidad_id.required" => "Elija la especialidad.",
            "medico_especialidad_id.integer" => "La especialidad elegida no es válida.",
            "dia_semana.required" => "Elija el día de la semana.",
            "dia_semana.integer" => "El día de la semana no es válido.",
            "dia_semana.between" => "El día de la semana no es válido.",
            "hora_inicio.required" => "El campo Hora inicial es obligatorio.",
            "hora_inicio.date_format" => "La hora inicial debe tener el formato HH:MM.",
            "hora_fin.required" => "El campo Hora final es obligatorio.",
            "hora_fin.date_format" => "La hora final debe tener el formato HH:MM.",
            "duracion_ficha_min.required" => "El campo Duración de la ficha es obligatorio.",
            "duracion_ficha_min.integer" => "La duración debe ser un número entero de minutos.",
            "duracion_ficha_min.between" => "La duración debe estar entre 5 y 240 minutos.",
            "modalidad.required" => "Elija la modalidad.",
            "modalidad.in" => "La modalidad debe ser Presencial o Virtual.",
            "consultorio_id.required_if" => "El consultorio es obligatorio para la atención presencial",
            "consultorio_id.prohibited_if" => "Un horario virtual no lleva consultorio.",
            "consultorio_id.integer" => "El consultorio elegido no es válido.",
            "consultorio_id.exists" => "El consultorio elegido no existe o no está activo.",
            "vigente_desde.required" => "El campo Vigente desde es obligatorio.",
            "vigente_desde.date" => "La fecha Vigente desde no es válida.",
            "vigente_desde.after_or_equal" => "La vigencia no puede empezar en una fecha pasada",
            "vigente_hasta.date" => "La fecha Vigente hasta no es válida.",
            "vigente_hasta.after_or_equal" => "La fecha Vigente hasta no puede ser anterior a Vigente desde.",
        ];
    }
}
