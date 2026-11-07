<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ParametroLaboratorio;
use App\Models\RangoReferencia;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CU33 Gestionar catálogo de laboratorio (RF-34): rangos de referencia por edad (meses) y sexo, con
 * vigencia, de los parámetros NUMERICO. Requiere el permiso laboratorio.gestionar. No hay borrado (RN11).
 * Un rango ya usado por algún resultado no se modifica (tg_rangos_referencia_usado): se cierra su
 * vigencia y se registra una versión nueva. Los rangos no se cruzan (ex_rangos_referencia_solape).
 */
class RangoReferenciaController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["edad_min_meses", "edad_max_meses", "sexo", "valor_min", "valor_max", "critico_min", "critico_max", "vigente_desde", "vigente_hasta"];

    // CU33 paso 3: registrar un rango de un parámetro NUMERICO. El parámetro viaja oculto.
    public function store(Request $request)
    {
        $request->validate(["parametro_laboratorio_id" => ["required", "integer", "exists:parametros_laboratorio,id"]], $this->mensajes());
        $parametro = ParametroLaboratorio::findOrFail($request->input("parametro_laboratorio_id"));
        $volver = redirect()->route("tipos-examen.mostrar", $parametro->tipo_examen_id);

        // Un parámetro de texto no lleva rangos.
        if ($parametro->tipo_valor !== "NUMERICO") {
            return $volver->withErrors(["operacion" => "Solo los parámetros numéricos tienen rangos de referencia."]);
        }

        $datos = $this->validar($request, $parametro->id);

        try {
            DB::transaction(function () use ($parametro, $datos, $request) {
                $rango = RangoReferencia::create($datos + ["parametro_laboratorio_id" => $parametro->id]);

                Bitacora::registrar("CREAR_RANGO", "laboratorio", $request->user()->id, true, "rangos_referencia", $rango->id, "WEB", null, $this->foto($rango));
            });
        } catch (QueryException $e) {
            return $this->errorBase($e);
        }

        return $volver->with("exito", "Rango de referencia registrado");
    }

    // CU33 paso 3: modificar un rango que todavía no se usó (A4: uno usado solo se cierra). El id viaja oculto.
    public function update(Request $request)
    {
        $rango = $this->rangoDelFormulario($request);
        $volver = redirect()->route("tipos-examen.mostrar", $rango->parametro->tipo_examen_id);

        // CU33 A4 / tg_rangos_referencia_usado.
        if ($rango->resultados_count > 0) {
            return $volver->withErrors(["operacion" => "El rango está usado en {$rango->resultados_count} resultado(s): no se edita. Cierre su vigencia y registre una versión nueva."]);
        }

        $datos = $this->validar($request, $rango->parametro_laboratorio_id, $rango->id);
        $anteriores = $this->foto($rango);

        try {
            DB::transaction(function () use ($rango, $datos, $anteriores, $request) {
                $rango->update($datos);

                Bitacora::registrar("MODIFICAR_RANGO", "laboratorio", $request->user()->id, true, "rangos_referencia", $rango->id, "WEB", $anteriores, $this->foto($rango));
            });
        } catch (QueryException $e) {
            return $this->errorBase($e);
        }

        return $volver->with("exito", "Rango de referencia actualizado");
    }

    // CU33 A4: delete = cerrar vigencia (RN23: no se borra). Fija vigente_hasta de un rango, usado o no. El id viaja oculto.
    public function delete(Request $request)
    {
        $rango = $this->rangoDelFormulario($request);
        $volver = redirect()->route("tipos-examen.mostrar", $rango->parametro->tipo_examen_id);

        $datos = Validator::make($request->all(), [
            "vigente_hasta" => ["required", "date_format:Y-m-d", "after_or_equal:" . $rango->vigente_desde->toDateString()],
        ], [
            "vigente_hasta.required" => "Indique hasta qué fecha está vigente el rango.",
            "vigente_hasta.date_format" => "La fecha de cierre no es válida.",
            "vigente_hasta.after_or_equal" => "La fecha de cierre no puede ser anterior al inicio de la vigencia (" . $rango->vigente_desde->format("d/m/Y") . ").",
        ])->validate();

        // Cerrar no alarga la vigencia (podría cruzarse con la versión siguiente).
        if ($rango->vigente_hasta !== null && $datos["vigente_hasta"] > $rango->vigente_hasta->toDateString()) {
            return $volver->withErrors(["operacion" => "El rango ya termina el " . $rango->vigente_hasta->format("d/m/Y") . ": la fecha de cierre no puede ser posterior."]);
        }

        DB::transaction(function () use ($rango, $datos, $request) {
            $anterior = $rango->vigente_hasta?->toDateString();
            $rango->update(["vigente_hasta" => $datos["vigente_hasta"]]);

            Bitacora::registrar("CERRAR_VIGENCIA_RANGO", "laboratorio", $request->user()->id, true, "rangos_referencia", $rango->id, "WEB",
                ["vigente_hasta" => $anterior], ["vigente_hasta" => $datos["vigente_hasta"]]);
        });

        return $volver->with("exito", "Vigencia cerrada al " . $rango->vigente_hasta->format("d/m/Y") . ". Registre la versión nueva desde el día siguiente.");
    }

    // El rango del id oculto (modificar y cerrar), con su parámetro y la cantidad de resultados que lo usan.
    private function rangoDelFormulario(Request $request): RangoReferencia
    {
        $request->validate($this->reglaId(), $this->mensajes());

        return RangoReferencia::with("parametro")->withCount("resultados")->findOrFail($request->input("id"));
    }

    // CU33 paso 3: reglas() más las relaciones de ck_rangos_valores y el cruce con otro rango (EXCLUDE).
    private function validar(Request $request, int $parametroId, ?int $idIgnorar = null): array
    {
        $validador = Validator::make($request->all(), $this->reglas(), $this->mensajes());
        $validador->after(function ($validador) use ($request, $parametroId, $idIgnorar) {
            $errores = $validador->errors();
            if ($errores->isNotEmpty()) {
                return;
            }
            $v = fn ($campo) => $request->input($campo) === null ? null : (float) $request->input($campo);

            // ck_rangos_valores: al menos un límite normal; crítico mín ≤ mín ≤ máx ≤ crítico máx.
            if ($v("valor_min") === null && $v("valor_max") === null) {
                $errores->add("valor_min", "Indique el valor mínimo, el máximo o ambos.");
            }
            if ($v("valor_min") !== null && $v("valor_max") !== null && $v("valor_max") < $v("valor_min")) {
                $errores->add("valor_max", "El valor máximo no puede ser menor que el mínimo.");
            }
            if ($v("critico_min") !== null && $v("valor_min") !== null && $v("critico_min") > $v("valor_min")) {
                $errores->add("critico_min", "El crítico mínimo no puede ser mayor que el valor mínimo.");
            }
            if ($v("critico_max") !== null && $v("valor_max") !== null && $v("critico_max") < $v("valor_max")) {
                $errores->add("critico_max", "El crítico máximo no puede ser menor que el valor máximo.");
            }
            if ($v("critico_min") !== null && $v("critico_max") !== null && $v("critico_max") < $v("critico_min")) {
                $errores->add("critico_max", "El crítico máximo no puede ser menor que el crítico mínimo.");
            }

            // CU33 A3 / ex_rangos_referencia_solape: no se cruza con otro rango del parámetro.
            if ($errores->isEmpty() && ($otro = RangoReferencia::solapado($parametroId, (int) $request->input("edad_min_meses"), (int) $request->input("edad_max_meses"),
                    $request->input("sexo") ?: null, $request->input("vigente_desde"), $request->input("vigente_hasta") ?: null, $idIgnorar))) {
                $errores->add("rango", "Se cruza con el rango " . $otro->texto() . " (" . $otro->descripcion() . "). Ajuste la edad, el sexo o la vigencia, o cierre la vigencia de ese rango.");
            }
        });

        // «Ambos» llega vacío: sexo = null. Los campos opcionales que no lleguen quedan en null.
        $datos = array_intersect_key($validador->validate(), array_flip(self::CAMPOS)) + array_fill_keys(self::CAMPOS, null);
        $datos["sexo"] = $datos["sexo"] ?: null;

        return $datos;
    }

    // CU33 A3 / A4: errores de la base con un mensaje claro (el cruce del EXCLUDE, 23P01, o el trigger
    // de rango usado). Cualquier otro error sigue su curso.
    private function errorBase(QueryException $e)
    {
        if (str_contains($e->getMessage(), "ex_rangos_referencia_solape")) {
            return back()->withErrors(["rango" => "El rango se cruza con otro del mismo parámetro, sexo, edad y vigencia."])->withInput();
        }
        if (str_contains($e->getMessage(), "ya fue usado")) {
            return back()->withErrors(["operacion" => "El rango ya fue usado por un resultado: cierre su vigencia y registre una versión nueva."]);
        }

        throw $e;
    }

    // Datos del rango para la bitácora.
    private function foto(RangoReferencia $rango): array
    {
        return array_merge($rango->only(array_merge(["parametro_laboratorio_id"], self::CAMPOS)), [
            "vigente_desde" => $rango->vigente_desde?->toDateString(),
            "vigente_hasta" => $rango->vigente_hasta?->toDateString(),
        ]);
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:rangos_referencia,id"]];
    }

    // CU33 paso 3: edades en meses (ck_rangos_edades), sexo (ck_rangos_sexo), valores numéricos y vigencia (ck_rangos_vigencia).
    private function reglas(): array
    {
        return [
            "edad_min_meses" => ["required", "integer", "between:0,1500"],
            "edad_max_meses" => ["required", "integer", "between:0,1500", "gte:edad_min_meses"],
            "sexo" => ["nullable", Rule::in(["M", "F"])],
            "valor_min" => ["nullable", "numeric"],
            "valor_max" => ["nullable", "numeric"],
            "critico_min" => ["nullable", "numeric"],
            "critico_max" => ["nullable", "numeric"],
            "vigente_desde" => ["required", "date_format:Y-m-d"],
            "vigente_hasta" => ["nullable", "date_format:Y-m-d", "after_or_equal:vigente_desde"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el rango.",
            "id.integer" => "El rango indicado no es válido.",
            "id.exists" => "El rango indicado no existe.",
            "parametro_laboratorio_id.required" => "No se indicó el parámetro.",
            "parametro_laboratorio_id.integer" => "El parámetro indicado no es válido.",
            "parametro_laboratorio_id.exists" => "El parámetro indicado no existe.",
            "edad_min_meses.required" => "Indique la edad mínima en meses.",
            "edad_min_meses.integer" => "La edad mínima debe ser un número entero de meses.",
            "edad_min_meses.between" => "La edad mínima debe estar entre 0 y 1500 meses.",
            "edad_max_meses.required" => "Indique la edad máxima en meses.",
            "edad_max_meses.integer" => "La edad máxima debe ser un número entero de meses.",
            "edad_max_meses.between" => "La edad máxima debe estar entre 0 y 1500 meses.",
            "edad_max_meses.gte" => "La edad máxima no puede ser menor que la mínima.",
            "sexo.in" => "El sexo debe ser Ambos, Masculino o Femenino.",
            "valor_min.numeric" => "El valor mínimo debe ser un número.",
            "valor_max.numeric" => "El valor máximo debe ser un número.",
            "critico_min.numeric" => "El crítico mínimo debe ser un número.",
            "critico_max.numeric" => "El crítico máximo debe ser un número.",
            "vigente_desde.required" => "Indique desde qué fecha está vigente el rango.",
            "vigente_desde.date_format" => "La fecha Vigente desde no es válida.",
            "vigente_hasta.date_format" => "La fecha Vigente hasta no es válida.",
            "vigente_hasta.after_or_equal" => "La fecha Vigente hasta no puede ser anterior a Vigente desde.",
        ];
    }
}
