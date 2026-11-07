<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\Consulta;
use App\Models\OrdenLaboratorio;
use App\Services\LaboratorioService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * CU34 Solicitar orden de laboratorio (RF-35). Solo el médico de la consulta (perfil ACTIVO) con
 * consultas.gestionar, desde la consulta EN_CURSO (consultas/show). No hay borrado físico (RN11,
 * trigger sin DELETE): una orden SOLICITADA se anula. Las reglas de negocio están en LaboratorioService.
 */
class OrdenLaboratorioController extends Controller
{
    // Campos de la orden que llegan del formulario.
    private const CAMPOS = ["tipo_examen_id", "prioridad"];

    public function __construct(private LaboratorioService $laboratorio)
    {
    }

    // CU34 pasos 1 y 2: solicitar un examen en la consulta. La consulta viaja oculta.
    public function store(Request $request)
    {
        $request->validate(["consulta_id" => ["required", "integer", "exists:consultas,id"]], $this->mensajes());
        $consulta = Consulta::findOrFail($request->input("consulta_id"));
        $datos = $request->validate($this->reglas(), $this->mensajes());

        try {
            $orden = $this->laboratorio->solicitar($consulta, Arr::only($datos, self::CAMPOS), $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["orden" => $e->getMessage()])->withInput();
        }

        return redirect()->route("consultas.mostrar", $consulta->id)->with("exito", "Orden {$orden->codigo} solicitada");
    }

    // CU34 paso 3: delete = anular (RN11: no se borra). Una orden SOLICITADA, solo el médico que la pidió. El id viaja oculto.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $orden = OrdenLaboratorio::findOrFail($request->input("id"));

        try {
            $orden = $this->laboratorio->anular($orden, $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["operacion" => $e->getMessage()]);
        }

        return back()->with("exito", "Orden {$orden->codigo} anulada");
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:ordenes_laboratorio,id"]];
    }

    // CU34 paso 1: examen y prioridad (ck_ordenes_prioridad). Que el examen esté ACTIVO y con parámetros lo revisa LaboratorioService.
    private function reglas(): array
    {
        return [
            "tipo_examen_id" => ["required", "integer", "exists:tipos_examen,id"],
            "prioridad" => ["required", Rule::in(array_keys(OrdenLaboratorio::PRIORIDADES))],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la orden.",
            "id.integer" => "La orden indicada no es válida.",
            "id.exists" => "La orden indicada no existe.",
            "consulta_id.required" => "No se indicó la consulta.",
            "consulta_id.integer" => "La consulta indicada no es válida.",
            "consulta_id.exists" => "La consulta indicada no existe.",
            "tipo_examen_id.required" => "Elija el examen.",
            "tipo_examen_id.integer" => "El examen elegido no es válido.",
            "tipo_examen_id.exists" => "El examen elegido no existe.",
            "prioridad.required" => "Elija la prioridad.",
            "prioridad.in" => "La prioridad no es válida.",
        ];
    }
}
