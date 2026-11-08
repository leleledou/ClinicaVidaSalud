<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\Cita;
use App\Models\Consulta;
use App\Models\OrdenLaboratorio;
use App\Models\Prescripcion;
use App\Models\TipoExamen;
use App\Models\Tratamiento;
use App\Services\ConsultaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * CU31 Atender consulta médica (RF-32). Solo el médico de la cita (perfil ACTIVO) con consultas.gestionar;
 * cualquier otro, 403. Inicia la consulta, registra signos vitales, diagnóstico CIE-10 e indicaciones y la
 * finaliza; al finalizar la cita queda ATENDIDA. No hay borrado (RN11): una consulta FINALIZADA no se
 * modifica (RN10). Las reglas de negocio están en ConsultaService.
 */
class ConsultaController extends Controller
{
    // Campos clínicos que se guardan desde el formulario (todos opcionales mientras está EN_CURSO).
    private const CAMPOS = Consulta::CAMPOS_CLINICOS;

    public function __construct(private ConsultaService $consultas)
    {
    }

    // CU31 paso 7: «Mis consultas» del médico (EN_CURSO primero y las finalizadas de los últimos 30 días).
    public function index(Request $request)
    {
        $medico = Consulta::medicoQueAtiende($request->user());
        abort_if($medico === null, 403);

        $consultas = Consulta::misConsultas($medico->id)->paginate(15);

        return view("consultas.index", compact("consultas"));
    }

    // CU31 paso 2: iniciar la consulta de una cita (o abrir la existente). La cita viaja oculta.
    public function store(Request $request)
    {
        $request->validate(["cita_id" => ["required", "integer", "exists:citas,id"]], $this->mensajes());
        $cita = Cita::findOrFail($request->input("cita_id"));

        try {
            $consulta = $this->consultas->iniciar($cita, $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["cita" => $e->getMessage()]);
        }

        return redirect()->route("consultas.mostrar", $consulta->id)
            ->with("exito", $consulta->wasRecentlyCreated ? "Consulta iniciada" : "La cita ya tenía una consulta: se abrió la existente.");
    }

    // CU31 paso 4 / CU32: la consulta con el paciente, sus alergias activas, su medicación habitual y
    // antecedentes, el formulario clínico y los tratamientos con sus prescripciones.
    public function show(Request $request, $id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $consulta = Consulta::with([
            "cita.paciente.persona", "cita.ficha.horario.medicoEspecialidad.medico.persona", "cita.ficha.horario.medicoEspecialidad.especialidad",
            "cita.paciente.historiaClinica.alergias" => fn ($alergia) => $alergia->where("activa", true)->orderBy("sustancia"),
            "cita.paciente.historiaClinica.antecedentes" => fn ($antecedente) => $antecedente->where("vigente", true)->orderBy("tipo"),
            "tratamientos" => fn ($tratamiento) => $tratamiento->orderBy("fecha_inicio")->orderBy("id"),
            "tratamientos.prescripciones", "tratamientos.resultadoLaboratorio.parametro",
            // CU34 paso 3: mostrar las órdenes de laboratorio de la consulta.
            "ordenes" => fn ($orden) => $orden->orderBy("fecha_solicitud")->orderBy("id"),
            "ordenes.tipoExamen",
        ])->findOrFail($id);

        // CU31 paso 1: solo el médico de la cita.
        $medico = Consulta::medicoQueAtiende($request->user());
        abort_unless($medico && $consulta->cita->medico()->id === $medico->id, 403);

        $enCurso = $consulta->estado === "EN_CURSO";
        $paciente = $consulta->cita->paciente;

        return view("consultas.show", [
            "consulta" => $consulta,
            "paciente" => $paciente,
            "enCurso" => $enCurso,
            // CU32 pasos 1-2: tratamientos y prescripciones solo en una consulta EN_CURSO; evolución (paso 4) siempre.
            "puedeTratar" => $enCurso && $request->user()->tienePermiso("tratamientos.gestionar"),
            "puedeEvolucion" => $request->user()->tienePermiso("tratamientos.gestionar"),
            "resultados" => $enCurso ? $paciente->resultadosLaboratorio()->get() : collect(),
            "vias" => Prescripcion::viasDisponibles(),
            "estadosTratamiento" => Tratamiento::ESTADOS,
            "respuestas" => Tratamiento::RESPUESTAS,
            // CU34 paso 1: exámenes ACTIVOS y con parámetros, solo mientras la consulta está EN_CURSO.
            "examenes" => $enCurso ? TipoExamen::solicitables()->get() : collect(),
            "prioridades" => OrdenLaboratorio::PRIORIDADES,
        ]);
    }

    // CU31 paso 5: guardar el avance (solo EN_CURSO). El id viaja oculto.
    public function update(Request $request)
    {
        $consulta = $this->consultaDelFormulario($request);
        $datos = $this->validar($request);

        try {
            $cambiados = $this->consultas->guardar($consulta, $datos, $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["operacion" => $e->getMessage()])->withInput();
        }

        return redirect()->route("consultas.mostrar", $consulta->id)
            ->with($cambiados ? "exito" : "aviso", $cambiados ? "Avance de la consulta guardado" : "No hay cambios");
    }

    // CU31 paso 6: finalizar (exige CIE-10 y diagnóstico; la cita queda ATENDIDA). El id viaja oculto.
    public function finalizar(Request $request)
    {
        $consulta = $this->consultaDelFormulario($request);
        $datos = $this->validar($request);

        try {
            $consulta = $this->consultas->finalizar($consulta, $datos, $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["operacion" => $e->getMessage()])->withInput();
        }

        return redirect()->route("consultas.mostrar", $consulta->id)->with("exito", "Consulta finalizada: la cita quedó atendida.");
    }

    // La consulta del id oculto (guardar y finalizar).
    private function consultaDelFormulario(Request $request): Consulta
    {
        $request->validate($this->reglaId(), $this->mensajes());

        return Consulta::findOrFail($request->input("id"));
    }

    // CU31 paso 5: formato y rangos; CIE-10 en mayúsculas antes de validar; sistólica >= diastólica.
    private function validar(Request $request): array
    {
        if (is_string($request->input("codigo_cie10"))) {
            $request->merge(["codigo_cie10" => mb_strtoupper(trim($request->input("codigo_cie10"))) ?: null]);
        }
        foreach (["anamnesis", "examen_fisico", "diagnostico", "indicaciones"] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => trim($request->input($campo)) ?: null]);
            }
        }

        $validador = Validator::make($request->all(), $this->reglas(), $this->mensajes());
        // ck_consultas_signos_vitales: la sistólica no puede ser menor que la diastólica.
        $validador->after(function ($validador) use ($request) {
            $sistolica = $request->input("presion_sistolica");
            $diastolica = $request->input("presion_diastolica");
            if (! $validador->errors()->hasAny(["presion_sistolica", "presion_diastolica"]) && is_numeric($sistolica) && is_numeric($diastolica) && $sistolica < $diastolica) {
                $validador->errors()->add("presion_sistolica", "La presión sistólica no puede ser menor que la diastólica.");
            }
        });

        return array_intersect_key($validador->validate(), array_flip(self::CAMPOS));
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:consultas,id"]];
    }

    // CU31 paso 5: todos opcionales; rangos de los signos vitales y formato CIE-10 (Consulta::FORMATO_CIE10).
    private function reglas(): array
    {
        return [
            "anamnesis" => ["nullable", "string", "max:5000"],
            "examen_fisico" => ["nullable", "string", "max:5000"],
            "peso_kg" => ["nullable", "numeric", "between:0.5,400"],
            "talla_cm" => ["nullable", "numeric", "between:30,250"],
            "presion_sistolica" => ["nullable", "integer", "between:50,260", "required_with:presion_diastolica"],
            "presion_diastolica" => ["nullable", "integer", "between:30,160", "required_with:presion_sistolica"],
            "temperatura_c" => ["nullable", "numeric", "between:30,45"],
            "frecuencia_cardiaca" => ["nullable", "integer", "between:20,250"],
            "saturacion_o2" => ["nullable", "integer", "between:50,100"],
            "codigo_cie10" => ["nullable", "string", "regex:" . Consulta::FORMATO_CIE10],
            "diagnostico" => ["nullable", "string", "max:2000"],
            "indicaciones" => ["nullable", "string", "max:5000"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la consulta.",
            "id.integer" => "La consulta indicada no es válida.",
            "id.exists" => "La consulta indicada no existe.",
            "cita_id.required" => "No se indicó la cita.",
            "cita_id.integer" => "La cita indicada no es válida.",
            "cita_id.exists" => "La cita indicada no existe.",
            "anamnesis.max" => "La anamnesis no puede superar los 5000 caracteres.",
            "examen_fisico.max" => "El examen físico no puede superar los 5000 caracteres.",
            "peso_kg.numeric" => "El peso debe ser un número.",
            "peso_kg.between" => "El peso debe estar entre 0,5 y 400 kg.",
            "talla_cm.numeric" => "La talla debe ser un número.",
            "talla_cm.between" => "La talla debe estar entre 30 y 250 cm.",
            "presion_sistolica.integer" => "La presión sistólica debe ser un número entero.",
            "presion_sistolica.between" => "La presión sistólica debe estar entre 50 y 260 mmHg.",
            "presion_sistolica.required_with" => "Indique también la presión sistólica.",
            "presion_diastolica.integer" => "La presión diastólica debe ser un número entero.",
            "presion_diastolica.between" => "La presión diastólica debe estar entre 30 y 160 mmHg.",
            "presion_diastolica.required_with" => "Indique también la presión diastólica.",
            "temperatura_c.numeric" => "La temperatura debe ser un número.",
            "temperatura_c.between" => "La temperatura debe estar entre 30 y 45 °C.",
            "frecuencia_cardiaca.integer" => "La frecuencia cardiaca debe ser un número entero.",
            "frecuencia_cardiaca.between" => "La frecuencia cardiaca debe estar entre 20 y 250 lpm.",
            "saturacion_o2.integer" => "La saturación debe ser un número entero.",
            "saturacion_o2.between" => "La saturación debe estar entre 50 y 100 %.",
            "codigo_cie10.regex" => "El código CIE-10 debe tener una letra, dos dígitos y, opcionalmente, un punto y hasta 4 caracteres (por ejemplo, D50.9).",
            "diagnostico.max" => "El diagnóstico no puede superar los 2000 caracteres.",
            "indicaciones.max" => "Las indicaciones no pueden superar los 5000 caracteres.",
        ];
    }
}
