<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ParametroLaboratorio;
use App\Models\RangoReferencia;
use App\Models\TipoExamen;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU33 Gestionar catálogo de laboratorio (RF-34): exámenes. Requiere el permiso laboratorio.gestionar.
 * La ficha del examen reúne sus parámetros y los rangos de referencia (ParametroLaboratorioController y
 * RangoReferenciaController). No hay borrado físico (RN11): desactivar cambia el estado a INACTIVO.
 */
class TipoExamenController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["codigo", "nombre", "tipo_muestra", "indicaciones_previas", "estado"];

    private const NOMBRE_REPETIDO = "Ya existe un examen con ese nombre";

    private const CODIGO_REPETIDO = "Ya existe un examen con ese código";

    // CU33 paso 1: lista de exámenes ordenada por nombre, con la cantidad de parámetros de cada uno.
    public function index()
    {
        $examenes = TipoExamen::withCount("parametros")->orderBy("nombre")->get();

        return view("laboratorio.examenes.index", compact("examenes"));
    }

    // CU33 paso 1: registrar un examen.
    public function store(Request $request)
    {
        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas(), $this->mensajes());
        $datos["estado"] = "ACTIVO";

        try {
            $examen = DB::transaction(function () use ($datos, $request) {
                $examen = TipoExamen::create($datos);

                Bitacora::registrar("CREAR_EXAMEN", "laboratorio", $request->user()->id, true, "tipos_examen", $examen->id, "WEB", null, $examen->only(self::CAMPOS));

                return $examen;
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        // CU33 paso 2: a la ficha del examen para registrar sus parámetros.
        return redirect()->route("tipos-examen.mostrar", $examen->id)->with("exito", "Examen registrado: agregue sus parámetros.");
    }

    // CU33 pasos 2 y 3: ficha del examen con sus parámetros y los rangos de cada parámetro NUMERICO.
    public function show($id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $examen = TipoExamen::with([
            "parametros" => fn ($parametro) => $parametro->withCount("resultados"),
            "parametros.rangos" => fn ($rango) => $rango->withCount("resultados")
                ->orderBy("edad_min_meses")->orderByRaw("coalesce(sexo, '')")->orderBy("vigente_desde"),
        ])->findOrFail($id);

        return view("laboratorio.examenes.show", [
            "examen" => $examen,
            "tiposValor" => ParametroLaboratorio::TIPOS_VALOR,
            "sexos" => RangoReferencia::SEXOS,
        ]);
    }

    // CU33 A1: modificar un examen. El id viaja oculto en el formulario.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $examen = TipoExamen::findOrFail($request->input("id"));

        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas($examen->id), $this->mensajes());

        $anteriores = $examen->only(self::CAMPOS);

        try {
            DB::transaction(function () use ($examen, $datos, $anteriores, $request) {
                $examen->update($datos);

                Bitacora::registrar("MODIFICAR_EXAMEN", "laboratorio", $request->user()->id, true, "tipos_examen", $examen->id, "WEB", $anteriores, $examen->only(self::CAMPOS));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return back()->with("exito", "Examen actualizado");
    }

    // CU33 A2: desactivar (RN11: no se borra; ya no se puede solicitar en una orden nueva). El id viaja oculto.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $examen = TipoExamen::findOrFail($request->input("id"));

        $this->cambiarEstado($examen, "INACTIVO", "DESACTIVAR_EXAMEN", $request);

        return back()->with("exito", "Examen desactivado: ya no se puede solicitar en órdenes nuevas.");
    }

    // CU33 A2: activar un examen inactivo. El id viaja oculto en el formulario.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $examen = TipoExamen::findOrFail($request->input("id"));

        $this->cambiarEstado($examen, "ACTIVO", "ACTIVAR_EXAMEN", $request);

        return back()->with("exito", "Examen activado");
    }

    // CU33 A2: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(TipoExamen $examen, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($examen, $estado, $accion, $request) {
            $anterior = $examen->estado;
            $examen->update(["estado" => $estado]);

            Bitacora::registrar($accion, "laboratorio", $request->user()->id, true, "tipos_examen", $examen->id, "WEB", ["estado" => $anterior], ["estado" => $estado]);
        });
    }

    // CU33 paso 1: nombre y tipo de muestra con un solo espacio entre palabras; código en mayúsculas y sin
    // espacios; indicaciones vacías = null.
    private function limpiarNombre(Request $request): void
    {
        foreach (["nombre", "tipo_muestra"] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => preg_replace("/\s+/u", " ", trim($request->input($campo)))]);
            }
        }
        if (is_string($request->input("codigo"))) {
            $request->merge(["codigo" => mb_strtoupper(preg_replace("/\s+/u", "", $request->input("codigo")))]);
        }
        if (is_string($request->input("indicaciones_previas"))) {
            $request->merge(["indicaciones_previas" => trim($request->input("indicaciones_previas")) ?: null]);
        }
    }

    // CU33 paso 1: si la base rechaza el nombre (ux_tipos_examen_nombre) o el código (tipos_examen_codigo_unique)
    // por repetidos (23505), se muestra el mismo mensaje que la validación. Cualquier otro error sigue su curso.
    private function nombreDuplicado(QueryException $e, Request $request)
    {
        if (($e->errorInfo[0] ?? null) === "23505") {
            if (str_contains($e->getMessage(), "ux_tipos_examen_nombre")) {
                return back()->withErrors(["nombre" => self::NOMBRE_REPETIDO])->withInput();
            }
            if (str_contains($e->getMessage(), "tipos_examen_codigo_unique")) {
                return back()->withErrors(["codigo" => self::CODIGO_REPETIDO])->withInput();
            }
        }

        throw $e;
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:tipos_examen,id"]];
    }

    // CU33 paso 1: código y nombre únicos, tipo de muestra obligatorio, indicaciones opcionales.
    // Al modificar, $idIgnorar es el propio examen.
    private function reglas(?int $idIgnorar = null): array
    {
        return [
            "codigo" => ["bail", "required", "string", "regex:" . TipoExamen::FORMATO_CODIGO, Rule::unique("tipos_examen", "codigo")->ignore($idIgnorar)],
            "nombre" => [
                "bail", "required", "string", "max:255",
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    if (TipoExamen::nombreUsado($valor, $idIgnorar)) {
                        $fallar(self::NOMBRE_REPETIDO);
                    }
                },
            ],
            "tipo_muestra" => ["required", "string", "max:255"],
            "indicaciones_previas" => ["nullable", "string", "max:2000"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el examen.",
            "id.integer" => "El examen indicado no es válido.",
            "id.exists" => "El examen indicado no existe.",
            "codigo.required" => "El campo Código es obligatorio.",
            "codigo.regex" => "El código solo puede tener letras mayúsculas, dígitos y guion bajo (de 2 a 30 caracteres), por ejemplo PERFIL_LIPIDICO.",
            "codigo.unique" => self::CODIGO_REPETIDO,
            "nombre.required" => "El campo Nombre es obligatorio.",
            "nombre.max" => "El nombre no puede superar los 255 caracteres.",
            "tipo_muestra.required" => "El campo Tipo de muestra es obligatorio.",
            "tipo_muestra.max" => "El tipo de muestra no puede superar los 255 caracteres.",
            "indicaciones_previas.max" => "Las indicaciones previas no pueden superar los 2000 caracteres.",
        ];
    }
}
