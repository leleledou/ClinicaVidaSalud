<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ParametroLaboratorio;
use App\Models\TipoExamen;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU33 Gestionar catálogo de laboratorio (RF-34): parámetros de un examen, en la ficha del examen.
 * Requiere el permiso laboratorio.gestionar. No hay borrado (RN11). A3 (paso 2): un parámetro con resultados
 * no cambia su tipo de valor ni su unidad.
 */
class ParametroLaboratorioController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["nombre", "unidad", "tipo_valor", "orden"];

    private const NOMBRE_REPETIDO = "El examen ya tiene un parámetro con ese nombre";

    private const ORDEN_REPETIDO = "El examen ya tiene un parámetro con ese orden";

    // CU33 paso 2: registrar un parámetro. El examen viaja oculto.
    public function store(Request $request)
    {
        $request->validate(["tipo_examen_id" => ["required", "integer", "exists:tipos_examen,id"]], $this->mensajes());
        $examen = TipoExamen::findOrFail($request->input("tipo_examen_id"));

        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas($examen->id), $this->mensajes());

        try {
            DB::transaction(function () use ($examen, $datos, $request) {
                $parametro = ParametroLaboratorio::create($datos + ["tipo_examen_id" => $examen->id]);

                Bitacora::registrar("CREAR_PARAMETRO", "laboratorio", $request->user()->id, true, "parametros_laboratorio", $parametro->id, "WEB", null, $this->foto($parametro));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return redirect()->route("tipos-examen.mostrar", $examen->id)->with("exito", "Parámetro registrado");
    }

    // CU33 paso 2: modificar un parámetro. A3 (paso 2): con resultados, no cambia su tipo de valor ni su unidad.
    // El id viaja oculto.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $parametro = ParametroLaboratorio::withCount(["resultados", "rangos"])->findOrFail($request->input("id"));

        $this->limpiarNombre($request);
        // Los numéricos sembrados sin unidad (pH, densidad) se siguen pudiendo editar sin inventarles una.
        $exigirUnidad = ! ($parametro->tipo_valor === "NUMERICO" && $parametro->unidad === null);
        $datos = $request->validate($this->reglas($parametro->tipo_examen_id, $parametro->id, $exigirUnidad), $this->mensajes());
        $volver = redirect()->route("tipos-examen.mostrar", $parametro->tipo_examen_id);

        // CU33 A3 (paso 2): con resultados cargados, el tipo de valor y la unidad quedan fijos.
        if ($parametro->tieneResultados() && ($datos["tipo_valor"] !== $parametro->tipo_valor || ($datos["unidad"] ?? null) !== $parametro->unidad)) {
            return $volver->withErrors(["operacion" => "El parámetro {$parametro->nombre} ya tiene {$parametro->resultados_count} resultado(s): no se cambia su tipo de valor ni su unidad."]);
        }
        // Un parámetro de texto no lleva rangos de referencia.
        if ($datos["tipo_valor"] === "TEXTO" && $parametro->rangos_count > 0) {
            return $volver->withErrors(["operacion" => "El parámetro {$parametro->nombre} tiene rangos de referencia: no puede pasar a texto."]);
        }

        $anteriores = $this->foto($parametro);

        try {
            DB::transaction(function () use ($parametro, $datos, $anteriores, $request) {
                $parametro->update($datos);

                Bitacora::registrar("MODIFICAR_PARAMETRO", "laboratorio", $request->user()->id, true, "parametros_laboratorio", $parametro->id, "WEB", $anteriores, $this->foto($parametro));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return $volver->with("exito", "Parámetro actualizado");
    }

    // CU33 paso 2: nombre y unidad sin espacios sobrantes (unidad vacía = null).
    private function limpiarNombre(Request $request): void
    {
        if (is_string($request->input("nombre"))) {
            $request->merge(["nombre" => preg_replace("/\s+/u", " ", trim($request->input("nombre")))]);
        }
        if (is_string($request->input("unidad"))) {
            $request->merge(["unidad" => trim($request->input("unidad")) ?: null]);
        }
    }

    // CU33 paso 2: si la base rechaza el nombre u orden repetidos (23505), el mismo mensaje que la validación.
    private function nombreDuplicado(QueryException $e, Request $request)
    {
        if (($e->errorInfo[0] ?? null) === "23505") {
            if (str_contains($e->getMessage(), "ux_parametros_examen_nombre")) {
                return back()->withErrors(["nombre" => self::NOMBRE_REPETIDO])->withInput();
            }
            if (str_contains($e->getMessage(), "ux_parametros_examen_orden")) {
                return back()->withErrors(["orden" => self::ORDEN_REPETIDO])->withInput();
            }
        }

        throw $e;
    }

    // Datos del parámetro para la bitácora.
    private function foto(ParametroLaboratorio $parametro): array
    {
        return $parametro->only(array_merge(["tipo_examen_id"], self::CAMPOS));
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:parametros_laboratorio,id"]];
    }

    // CU33 paso 2: nombre y orden únicos dentro del examen, tipo de valor de la lista y unidad obligatoria
    // si es NUMERICO ($exigirUnidad = false para un numérico que ya no tenía unidad). Al modificar,
    // $idIgnorar es el propio parámetro.
    private function reglas(int $examenId, ?int $idIgnorar = null, bool $exigirUnidad = true): array
    {
        return [
            "nombre" => [
                "bail", "required", "string", "max:255",
                function (string $atributo, mixed $valor, \Closure $fallar) use ($examenId, $idIgnorar) {
                    if (ParametroLaboratorio::yaUsadoEnExamen($examenId, "nombre", $valor, $idIgnorar)) {
                        $fallar(self::NOMBRE_REPETIDO);
                    }
                },
            ],
            "tipo_valor" => ["required", Rule::in(array_keys(ParametroLaboratorio::TIPOS_VALOR))],
            "unidad" => array_merge(["nullable"], $exigirUnidad ? ["required_if:tipo_valor,NUMERICO"] : [], ["string", "max:50"]),
            "orden" => [
                "bail", "required", "integer", "between:1,99",
                function (string $atributo, mixed $valor, \Closure $fallar) use ($examenId, $idIgnorar) {
                    if (ParametroLaboratorio::yaUsadoEnExamen($examenId, "orden", (int) $valor, $idIgnorar)) {
                        $fallar(self::ORDEN_REPETIDO);
                    }
                },
            ],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el parámetro.",
            "id.integer" => "El parámetro indicado no es válido.",
            "id.exists" => "El parámetro indicado no existe.",
            "tipo_examen_id.required" => "No se indicó el examen.",
            "tipo_examen_id.integer" => "El examen indicado no es válido.",
            "tipo_examen_id.exists" => "El examen indicado no existe.",
            "nombre.required" => "El campo Nombre es obligatorio.",
            "nombre.max" => "El nombre no puede superar los 255 caracteres.",
            "tipo_valor.required" => "Elija el tipo de valor.",
            "tipo_valor.in" => "El tipo de valor no es válido.",
            "unidad.required_if" => "La unidad es obligatoria en un parámetro numérico.",
            "unidad.max" => "La unidad no puede superar los 50 caracteres.",
            "orden.required" => "El campo Orden es obligatorio.",
            "orden.integer" => "El orden debe ser un número entero.",
            "orden.between" => "El orden debe estar entre 1 y 99.",
        ];
    }
}
