<?php

namespace App\Http\Controllers;

use App\Exceptions\AlergiaNoConfirmadaException;
use App\Exceptions\ReglaNegocioException;
use App\Models\Prescripcion;
use App\Models\Tratamiento;
use App\Services\ConsultaService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * CU32 Gestionar tratamientos y prescripciones (RF-33): prescripciones. Permiso tratamientos.gestionar;
 * solo el médico de la consulta y solo EN_CURSO. Advierte (no bloquea) las alergias ACTIVAS del paciente
 * (corrección 5 del diagrama v3.1). No se modifican ni se borran (RN11, trigger sin DELETE): para
 * corregir, se suspende el tratamiento. Las reglas de negocio están en ConsultaService.
 */
class PrescripcionController extends Controller
{
    // Campos de la prescripción que se guardan.
    private const CAMPOS = ["medicamento", "dosis", "frecuencia", "duracion_dias", "via"];

    public function __construct(private ConsultaService $consultas)
    {
    }

    // CU32 pasos 2 y 3: agregar una prescripción al tratamiento. El tratamiento viaja oculto.
    public function store(Request $request)
    {
        $request->validate(["tratamiento_id" => ["required", "integer", "exists:tratamientos,id"]], $this->mensajes());
        $tratamiento = Tratamiento::findOrFail($request->input("tratamiento_id"));

        foreach (["medicamento", "dosis", "frecuencia"] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => preg_replace("/\s+/u", " ", trim($request->input($campo)))]);
            }
        }
        $datos = $request->validate($this->reglas(), $this->mensajes());

        try {
            $this->consultas->registrarPrescripcion($tratamiento, Arr::only($datos, self::CAMPOS), $request->boolean("confirmar_alergia"), $request->user());
        } catch (AlergiaNoConfirmadaException $e) {
            // CU32 paso 3: no guarda; vuelve con el aviso y la casilla de confirmación.
            return back()->withErrors(["alergia" => $e->getMessage()])->withInput();
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["prescripcion" => $e->getMessage()])->withInput();
        }

        return redirect()->route("consultas.mostrar", $tratamiento->consulta_id)->with("exito", "Prescripción registrada");
    }

    // CU32 paso 2: medicamento, dosis y frecuencia obligatorios; duración de 1 a 365 días; vía de la lista.
    private function reglas(): array
    {
        return [
            "medicamento" => ["required", "string", "max:255"],
            "dosis" => ["required", "string", "max:255"],
            "frecuencia" => ["required", "string", "max:255"],
            "duracion_dias" => ["required", "integer", "between:1,365"],
            "via" => ["required", Rule::in(Prescripcion::viasDisponibles())],
            "confirmar_alergia" => ["nullable", "boolean"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "tratamiento_id.required" => "No se indicó el tratamiento.",
            "tratamiento_id.integer" => "El tratamiento indicado no es válido.",
            "tratamiento_id.exists" => "El tratamiento indicado no existe.",
            "medicamento.required" => "El campo Medicamento es obligatorio.",
            "medicamento.max" => "El medicamento no puede superar los 255 caracteres.",
            "dosis.required" => "El campo Dosis es obligatorio.",
            "dosis.max" => "La dosis no puede superar los 255 caracteres.",
            "frecuencia.required" => "El campo Frecuencia es obligatorio.",
            "frecuencia.max" => "La frecuencia no puede superar los 255 caracteres.",
            "duracion_dias.required" => "El campo Duración es obligatorio.",
            "duracion_dias.integer" => "La duración debe ser un número entero de días.",
            "duracion_dias.between" => "La duración debe estar entre 1 y 365 días.",
            "via.required" => "Elija la vía de administración.",
            "via.in" => "La vía de administración no es válida.",
            "confirmar_alergia.boolean" => "El valor de la confirmación no es válido.",
        ];
    }
}
