<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\Consulta;
use App\Models\Tratamiento;
use App\Services\ConsultaService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * CU32 Gestionar tratamientos y prescripciones (RF-33): tratamientos. Permiso tratamientos.gestionar.
 * Registrar: el médico de la consulta, solo EN_CURSO. Registrar la evolución: cualquier médico con
 * acceso clínico al paciente, aunque la consulta esté finalizada. No hay borrado (RN11, trigger sin
 * DELETE): para corregir, el tratamiento se suspende. Las reglas de negocio están en ConsultaService.
 */
class TratamientoController extends Controller
{
    // Campos del tratamiento que se guardan desde los formularios.
    private const CAMPOS = ["descripcion", "fecha_inicio", "fecha_fin", "resultado_laboratorio_id", "estado", "respuesta", "observacion_evolucion"];

    public function __construct(private ConsultaService $consultas)
    {
    }

    // CU32 paso 1: registrar un tratamiento en la consulta EN_CURSO. La consulta viaja oculta.
    public function store(Request $request)
    {
        $request->validate(["consulta_id" => ["required", "integer", "exists:consultas,id"]], $this->mensajes());
        $consulta = Consulta::findOrFail($request->input("consulta_id"));

        if (is_string($request->input("descripcion"))) {
            $request->merge(["descripcion" => trim($request->input("descripcion"))]);
        }
        $datos = $request->validate($this->reglas("registrar"), $this->mensajes());

        try {
            $this->consultas->registrarTratamiento($consulta, Arr::only($datos, self::CAMPOS), $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["tratamiento" => $e->getMessage()])->withInput();
        }

        return redirect()->route("consultas.mostrar", $consulta->id)->with("exito", "Tratamiento registrado");
    }

    // CU32 paso 4: registrar la evolución (estado, respuesta, observación y fin). Desde la consulta o el cardex.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $tratamiento = Tratamiento::findOrFail($request->input("id"));

        if (is_string($request->input("observacion_evolucion"))) {
            $request->merge(["observacion_evolucion" => trim($request->input("observacion_evolucion")) ?: null]);
        }
        $datos = $request->validate($this->reglas("evolucion"), $this->mensajes());

        try {
            $cambiados = $this->consultas->registrarEvolucion($tratamiento, Arr::only($datos, self::CAMPOS), $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["evolucion_" . $tratamiento->id => $e->getMessage()])->withInput();
        }

        return back()->with($cambiados ? "exito" : "aviso", $cambiados ? "Evolución del tratamiento registrada" : "No hay cambios");
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:tratamientos,id"]];
    }

    // CU32 paso 1 (registrar) y paso 4 (evolución). La relación entre fechas y el resultado del mismo
    // paciente los revisa ConsultaService.
    private function reglas(string $accion): array
    {
        return match ($accion) {
            "evolucion" => [
                "estado" => ["required", Rule::in(array_keys(Tratamiento::ESTADOS))],
                "respuesta" => ["required", Rule::in(array_keys(Tratamiento::RESPUESTAS))],
                "observacion_evolucion" => ["nullable", "string", "max:2000"],
                "fecha_fin" => ["nullable", "date_format:Y-m-d", "required_unless:estado,EN_CURSO"],
            ],
            default => [
                "descripcion" => ["required", "string", "max:2000"],
                "fecha_inicio" => ["required", "date_format:Y-m-d"],
                "fecha_fin" => ["nullable", "date_format:Y-m-d", "after_or_equal:fecha_inicio"],
                "resultado_laboratorio_id" => ["nullable", "integer", "exists:resultados_laboratorio,id"],
            ],
        };
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el tratamiento.",
            "id.integer" => "El tratamiento indicado no es válido.",
            "id.exists" => "El tratamiento indicado no existe.",
            "consulta_id.required" => "No se indicó la consulta.",
            "consulta_id.integer" => "La consulta indicada no es válida.",
            "consulta_id.exists" => "La consulta indicada no existe.",
            "descripcion.required" => "El campo Descripción es obligatorio.",
            "descripcion.max" => "La descripción no puede superar los 2000 caracteres.",
            "fecha_inicio.required" => "El campo Fecha de inicio es obligatorio.",
            "fecha_inicio.date_format" => "La fecha de inicio no es válida.",
            "fecha_fin.date_format" => "La fecha de fin no es válida.",
            "fecha_fin.after_or_equal" => "La fecha de fin no puede ser anterior a la de inicio.",
            "fecha_fin.required_unless" => "Indique la fecha de fin del tratamiento finalizado o suspendido.",
            "resultado_laboratorio_id.integer" => "El resultado de laboratorio elegido no es válido.",
            "resultado_laboratorio_id.exists" => "El resultado de laboratorio elegido no existe.",
            "estado.required" => "Elija el estado del tratamiento.",
            "estado.in" => "El estado del tratamiento no es válido.",
            "respuesta.required" => "Elija la respuesta del paciente.",
            "respuesta.in" => "La respuesta no es válida.",
            "observacion_evolucion.max" => "La observación no puede superar los 2000 caracteres.",
        ];
    }
}
