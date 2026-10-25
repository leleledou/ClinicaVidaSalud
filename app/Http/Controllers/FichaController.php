<?php

namespace App\Http\Controllers;

use App\Models\Consultorio;
use App\Models\Especialidad;
use App\Models\MedicoEspecialidad;
use App\Models\Paciente;
use App\Services\CitaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;

/**
 * CU14 Consultar disponibilidad de fichas (RF06, §3.4: por especialidad, ubicación y disponibilidad).
 * Página PÚBLICA (el actor incluye al Visitante). Solo consulta: ninguna ficha queda reservada.
 * Las fichas se generan en CU09 (AgendaService); aquí no se crean ni se modifican.
 */
class FichaController extends Controller
{
    // CU14 paso 2: rango máximo de días y búsqueda de la fecha disponible más próxima.
    private const MAX_DIAS = 31;
    private const DIAS_BUSQUEDA = 60;

    public const MODALIDADES = ["PRESENCIAL" => "Presencial", "VIRTUAL" => "Virtual"];

    public function __construct(private CitaService $citas)
    {
    }

    // CU14 pasos 1 a 6: filtros y fichas por médico y por día.
    public function index(Request $request)
    {
        // CU14 A1: paciente cuya edad y sexo filtran las especialidades (el propio o ?paciente_id= de recepción).
        $paciente = $this->pacienteDeContexto($request);

        // CU14 paso 1: solo especialidades ACTIVAS (A1: las que admiten al paciente hoy).
        $especialidades = Especialidad::where("estado", "ACTIVO")->orderBy("nombre")->get();
        if ($paciente) {
            $persona = $paciente->persona;
            $especialidades = $especialidades->filter(fn ($especialidad) => $especialidad->restriccionPara($persona->edad, $persona->sexo) === null)->values();
        }

        // CU14 paso 1: médicos ACTIVOS que ejercen cada especialidad (el select se recarga en el navegador).
        $medicosPorEspecialidad = MedicoEspecialidad::join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->where("medicos_especialidades.estado", "ACTIVO")
            ->where("medicos.estado", "ACTIVO")
            ->orderBy("personas.apellido_paterno")
            ->get(["medicos_especialidades.especialidad_id", "medicos.id", "personas.nombres", "personas.apellido_paterno", "personas.sexo"])
            ->groupBy("especialidad_id")
            ->map(fn ($medicos) => $medicos->map(fn ($m) => ["id" => $m->id, "nombre" => ($m->sexo === "F" ? "Dra. " : "Dr. ") . $m->nombres . " " . $m->apellido_paterno])->values());

        // CU14 paso 2: valores por defecto (hoy y hoy + 13 días).
        $filtros = [
            "especialidad_id" => $request->query("especialidad_id"),
            "medico_id" => $request->query("medico_id"),
            "desde" => $request->query("desde", today()->toDateString()),
            "hasta" => $request->query("hasta", today()->addDays(13)->toDateString()),
            "modalidad" => $request->query("modalidad"),
            "consultorio_id" => $request->query("consultorio_id"),
        ];

        $errores = new MessageBag();
        $medicos = collect();
        $proxima = null;
        $hayDisponibles = false;
        $consultado = $request->filled("especialidad_id");

        if ($consultado) {
            $validador = Validator::make($filtros, $this->reglas($especialidades->pluck("id")->all()), $this->mensajes());
            // CU14 paso 2: el rango no supera MAX_DIAS días.
            $validador->after(function ($validador) use ($filtros) {
                if (! $validador->errors()->hasAny(["desde", "hasta"]) && Carbon::parse($filtros["desde"])->diffInDays(Carbon::parse($filtros["hasta"])) > self::MAX_DIAS - 1) {
                    $validador->errors()->add("hasta", "El rango no puede superar " . self::MAX_DIAS . " días.");
                }
            });

            if ($validador->fails()) {
                $errores = $validador->errors();
                $consultado = false;
            } else {
                // CU14 pasos 3-4: fichas del rango con su estado calculado.
                $fichas = $this->filtrar($this->citas->consultaFichas(), $filtros)
                    ->whereBetween("fichas.fecha", [$filtros["desde"], $filtros["hasta"]])
                    // R-OCUP: ocupada = tiene una cita activa (no se guarda).
                    ->selectRaw("exists(select 1 from citas c where c.ficha_id = fichas.id and c.estado <> 'CANCELADA') as ocupada")
                    // Motivo del bloqueo que cruza la ficha (clínica, médico o consultorio), si lo hay.
                    ->selectRaw("(select b.motivo from bloqueos_agenda b where b.inicio < (fichas.fecha + fichas.hora_fin) and b.fin > (fichas.fecha + fichas.hora_inicio)
                        and (b.alcance = 'CLINICA' or (b.alcance = 'MEDICO' and b.medico_id = me.medico_id) or (b.alcance = 'CONSULTORIO' and b.consultorio_id = h.consultorio_id))
                        order by b.inicio limit 1) as motivo_bloqueo")
                    ->orderBy("personas.apellido_paterno")
                    ->orderBy("me.medico_id")
                    ->orderBy("fichas.fecha")
                    ->orderBy("fichas.hora_inicio")
                    ->get()
                    ->each(fn ($ficha) => $ficha->estado_visible = $ficha->estado === "BLOQUEADA" ? "BLOQUEADA" : ($ficha->ocupada ? "OCUPADA" : "DISPONIBLE"));

                // CU14 paso 3: agrupadas por médico y, dentro, por día.
                $medicos = $fichas->groupBy("medico_id")->map(fn ($deMedico) => [
                    "datos" => $deMedico->first(),
                    "lugares" => $deMedico->map(fn ($f) => $f->modalidad === "VIRTUAL" ? "Virtual (videollamada)" : "Presencial · {$f->consultorio} ({$f->ubicacion})")->unique()->values(),
                    "dias" => $deMedico->groupBy("fecha"),
                ]);

                // CU14 excepción: sin fichas disponibles, la fecha disponible más próxima (hasta 60 días después).
                $hayDisponibles = $fichas->contains("estado_visible", "DISPONIBLE");
                if (! $hayDisponibles) {
                    $fecha = $this->filtrar($this->citas->consultaFichas(), $filtros)
                        ->where("fichas.fecha", ">", $filtros["hasta"])
                        ->where("fichas.fecha", "<=", Carbon::parse($filtros["hasta"])->addDays(self::DIAS_BUSQUEDA)->toDateString())
                        ->where("fichas.estado", "HABILITADA")
                        ->whereNotExists($this->citas->citaActivaDeLaFicha())
                        ->min("fichas.fecha");
                    $proxima = $fecha ? Carbon::parse($fecha) : null;
                }
            }
        }

        $usuario = $request->user();

        return view("fichas.disponibilidad", [
            "especialidades" => $especialidades,
            "medicosPorEspecialidad" => $medicosPorEspecialidad,
            "consultorios" => Consultorio::where("estado", "ACTIVO")->orderBy("nombre")->get(["id", "nombre", "ubicacion"]),
            "modalidades" => self::MODALIDADES,
            "filtros" => $filtros,
            "errores" => $errores,
            "consultado" => $consultado,
            "medicos" => $medicos,
            "proxima" => $proxima,
            "hayDisponibles" => $hayDisponibles,
            "paciente" => $paciente,
            // CU14 paso 6: quien puede programar o reservar va al formulario de CU15.
            "puedeReservar" => $usuario && (Gate::allows("citas.programar") || Gate::allows("citas.autoservicio")),
            "maxDias" => self::MAX_DIAS,
        ]);
    }

    // CU14 paso 2: filtros opcionales (médico, modalidad y consultorio) sobre la especialidad.
    private function filtrar($consulta, array $filtros)
    {
        return $consulta->where("me.especialidad_id", (int) $filtros["especialidad_id"])
            ->when($filtros["medico_id"], fn ($c) => $c->where("me.medico_id", (int) $filtros["medico_id"]))
            ->when($filtros["modalidad"], fn ($c) => $c->where("h.modalidad", $filtros["modalidad"]))
            ->when($filtros["consultorio_id"], fn ($c) => $c->where("h.consultorio_id", (int) $filtros["consultorio_id"]));
    }

    // CU14 A1: el paciente que consulta (rol paciente) o el de ?paciente_id= para recepción.
    private function pacienteDeContexto(Request $request): ?Paciente
    {
        $usuario = $request->user();
        if ($usuario === null) {
            return null;
        }

        if (Gate::allows("citas.programar")) {
            $id = $request->query("paciente_id");

            return ctype_digit((string) $id) ? Paciente::with("persona")->find($id) : null;
        }

        return Gate::allows("citas.autoservicio") ? $usuario->persona?->paciente?->load("persona") : null;
    }

    // CU14 paso 2: reglas de los filtros. $especialidades: ids que se pueden elegir.
    private function reglas(array $especialidades): array
    {
        return [
            "especialidad_id" => ["required", "integer", "in:" . implode(",", $especialidades ?: [0])],
            "medico_id" => ["nullable", "integer"],
            "desde" => ["bail", "required", "date_format:Y-m-d", "after_or_equal:today"],
            "hasta" => ["bail", "required", "date_format:Y-m-d", "after_or_equal:desde"],
            "modalidad" => ["nullable", "in:" . implode(",", array_keys(self::MODALIDADES))],
            "consultorio_id" => ["nullable", "integer"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "especialidad_id.required" => "Elija la especialidad.",
            "especialidad_id.integer" => "La especialidad elegida no es válida.",
            "especialidad_id.in" => "La especialidad elegida no está disponible.",
            "medico_id.integer" => "El médico elegido no es válido.",
            "desde.required" => "Indique la fecha desde.",
            "desde.date_format" => "La fecha desde no es válida.",
            "desde.after_or_equal" => "La fecha desde no puede ser anterior a hoy.",
            "hasta.required" => "Indique la fecha hasta.",
            "hasta.date_format" => "La fecha hasta no es válida.",
            "hasta.after_or_equal" => "La fecha hasta no puede ser anterior a la fecha desde.",
            "modalidad.in" => "La modalidad elegida no es válida.",
            "consultorio_id.integer" => "El consultorio elegido no es válido.",
        ];
    }
}
