<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\OrdenLaboratorio;
use App\Services\LaboratorioService;
use Illuminate\Http\Request;

/**
 * CU35 Registrar resultados de laboratorio (RF-36, RN23, RN24). Solo la Recepcionista (laboratorio.cargar_resultados
 * sin perfil de médico; el médico solo revisa, CU36), desde la ficha de una orden SOLICITADA o MUESTRA_TOMADA. La toma de muestra
 * y los valores se registran en un solo paso. No hay modificación ni borrado (RN11): el rango aplicado
 * queda fijo (RN23). Las reglas de negocio (rango, interpretación, avisos) están en LaboratorioService.
 */
class ResultadoLaboratorioController extends Controller
{
    // Formato del campo datetime-local de la toma de muestra.
    private const FORMATO_TOMA = "Y-m-d\\TH:i";

    // Largo máximo de los textos (valor de texto y observación).
    private const MAX_TEXTO = 500;

    public function __construct(private LaboratorioService $laboratorio)
    {
    }

    // CU35 pasos 3 a 10: registrar la toma de muestra y los valores de la orden. La orden viaja oculta.
    public function store(Request $request)
    {
        $request->validate(["orden_laboratorio_id" => ["required", "integer", "exists:ordenes_laboratorio,id"]], $this->mensajes());
        $orden = OrdenLaboratorio::with("tipoExamen.parametros")->findOrFail($request->input("orden_laboratorio_id"));

        $this->limpiar($request, $orden);
        $datos = $request->validate($this->reglas($orden), $this->mensajes($orden), $this->atributos($orden));

        try {
            [$orden, $criticos] = $this->laboratorio->registrarResultados($orden, $datos, $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["operacion" => $e->getMessage()])->withInput();
        }

        // CU35 paso 10: confirmación y, si hubo críticos, el aviso.
        $volver = redirect()->route("ordenes-laboratorio.mostrar", $orden->id)->with("exito", "Resultados registrados");

        return $criticos > 0
            ? $volver->with("aviso", "Hay {$criticos} valor(es) crítico(s): se avisó al médico solicitante")
            : $volver;
    }

    // CU35 paso 3: textos sin espacios sobrantes (vacío = null) y decimales con coma o punto.
    private function limpiar(Request $request, OrdenLaboratorio $orden): void
    {
        $texto = fn ($valor) => is_string($valor) ? (trim($valor) === "" ? null : trim($valor)) : $valor;
        $valores = (array) $request->input("valores", []);
        $observaciones = (array) $request->input("observaciones", []);

        foreach ($orden->tipoExamen->parametros as $parametro) {
            $valor = $texto($valores[$parametro->id] ?? null);
            $valores[$parametro->id] = $parametro->tipo_valor === "NUMERICO" && is_string($valor) ? str_replace(",", ".", $valor) : $valor;
            $observaciones[$parametro->id] = $texto($observaciones[$parametro->id] ?? null);
        }

        $request->merge([
            "valores" => $valores,
            "observaciones" => $observaciones,
            "laboratorio_procesador" => $texto($request->input("laboratorio_procesador")),
        ]);
    }

    /**
     * CU35 paso 5: fecha de toma no futura ni anterior a la solicitud (A4); un valor por parámetro del
     * examen, número con hasta 4 decimales (decimal(12,4)) si es NUMERICO o texto si es TEXTO (A3).
     * LaboratorioService vuelve a revisarlo con la orden bloqueada.
     */
    private function reglas(OrdenLaboratorio $orden): array
    {
        $reglas = [
            "fecha_toma_muestra" => ["required", "date_format:" . self::FORMATO_TOMA,
                "after_or_equal:" . $orden->fecha_solicitud->format("Y-m-d H:i"), "before_or_equal:now"],
            "laboratorio_procesador" => ["nullable", "string", "max:255"],
            "valores" => ["required", "array"],
            "observaciones" => ["array"],
        ];
        foreach ($orden->tipoExamen->parametros as $parametro) {
            $reglas["valores.{$parametro->id}"] = $parametro->tipo_valor === "NUMERICO"
                ? ["bail", "required", "numeric", "regex:/^-?\d{1,8}(\.\d{1,4})?$/"]
                : ["required", "string", "max:" . self::MAX_TEXTO];
            $reglas["observaciones.{$parametro->id}"] = ["nullable", "string", "max:" . self::MAX_TEXTO];
        }

        return $reglas;
    }

    // Nombre de cada parámetro para los mensajes («Indique el valor de Hemoglobina.»).
    private function atributos(OrdenLaboratorio $orden): array
    {
        $atributos = [];
        foreach ($orden->tipoExamen->parametros as $parametro) {
            $atributos["valores.{$parametro->id}"] = $parametro->nombre;
            $atributos["observaciones.{$parametro->id}"] = $parametro->nombre;
        }

        return $atributos;
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(?OrdenLaboratorio $orden = null): array
    {
        return [
            "orden_laboratorio_id.required" => "No se indicó la orden.",
            "orden_laboratorio_id.integer" => "La orden indicada no es válida.",
            "orden_laboratorio_id.exists" => "La orden indicada no existe.",
            "fecha_toma_muestra.required" => "Indique la fecha y hora de la toma de muestra.",
            "fecha_toma_muestra.date_format" => "La fecha y hora de la toma de muestra no es válida.",
            "fecha_toma_muestra.after_or_equal" => "La toma de muestra no puede ser anterior a la solicitud ("
                . ($orden?->fecha_solicitud->format("d/m/Y H:i") ?? "") . ").",
            "fecha_toma_muestra.before_or_equal" => "La toma de muestra no puede estar en el futuro.",
            "laboratorio_procesador.max" => "El laboratorio no puede tener más de 255 caracteres.",
            "valores.required" => "Registre los valores de los parámetros.",
            "valores.array" => "Los valores no son válidos.",
            "observaciones.array" => "Las observaciones no son válidas.",
            "valores.*.required" => "Indique el valor de :attribute.",
            "valores.*.numeric" => "El valor de :attribute debe ser un número.",
            "valores.*.regex" => "El valor de :attribute admite hasta 8 cifras enteras y 4 decimales.",
            "valores.*.string" => "El valor de :attribute no es válido.",
            "valores.*.max" => "El valor de :attribute no puede tener más de " . self::MAX_TEXTO . " caracteres.",
            "observaciones.*.string" => "La observación de :attribute no es válida.",
            "observaciones.*.max" => "La observación de :attribute no puede tener más de " . self::MAX_TEXTO . " caracteres.",
        ];
    }
}
