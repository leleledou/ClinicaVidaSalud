<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use App\Models\Bitacora;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU23 Gestionar aseguradoras (RF-13). Requiere el permiso aseguradoras.gestionar.
 * No hay borrado físico (RN11): desactivar cambia el estado a INACTIVO. Las pólizas de una
 * aseguradora inactiva ya no sirven para citas nuevas (RN20, CitaService::validarSeguro).
 */
class AseguradoraController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["nombre", "nit", "telefono", "estado"];

    private const ESTADOS = ["ACTIVO", "INACTIVO"];

    private const NOMBRE_REPETIDO = "Ya existe una aseguradora con ese nombre";

    private const NIT_REPETIDO = "Ya existe una aseguradora con ese NIT";

    // CU23 pasos 1-2: lista paginada con búsqueda por nombre o NIT y filtro por estado, con la
    // cantidad de pólizas ACTIVAS de cada aseguradora.
    public function index(Request $request)
    {
        $texto = trim((string) $request->query("q", ""));
        $estado = in_array($request->query("estado"), self::ESTADOS, true) ? $request->query("estado") : null;

        $aseguradoras = Aseguradora::withCount([
                "seguros as polizas_activas_count" => fn ($consulta) => $consulta->where("estado", "ACTIVO"),
            ])
            // CU23 paso 2: nombre sin tildes ni mayúsculas (fn_normalizar_texto) o parte del NIT, con parámetros.
            ->when($texto !== "", function ($consulta) use ($texto) {
                $patron = "%" . $this->escaparLike($texto) . "%";
                $consulta->where(fn ($condicion) => $condicion
                    ->whereRaw("fn_normalizar_texto(nombre) LIKE fn_normalizar_texto(?)", [$patron])
                    ->orWhere("nit", "like", $patron));
            })
            ->when($estado, fn ($consulta) => $consulta->where("estado", $estado))
            ->orderBy("nombre")
            ->paginate(15)
            ->withQueryString();

        return view("aseguradoras.index", compact("aseguradoras", "texto", "estado"));
    }

    // CU23 pasos 3 a 6: registrar una aseguradora.
    public function store(Request $request)
    {
        // CU23 paso 4: limpiar y validar (nombre y NIT únicos).
        $this->limpiarNombre($request);
        $this->limpiarNitYTelefono($request);
        $datos = $request->validate($this->reglas(), $this->mensajes());

        // CU23 paso 5: guardar como ACTIVA y registrar en la bitácora.
        $datos["estado"] = "ACTIVO";

        try {
            DB::transaction(function () use ($datos, $request) {
                $aseguradora = Aseguradora::create($datos);

                Bitacora::registrar("CREAR_ASEGURADORA", "catalogos", $request->user()->id, true, "aseguradoras", $aseguradora->id, "WEB", null, $aseguradora->only(self::CAMPOS));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return redirect()->route("aseguradoras.listar")->with("exito", "Aseguradora registrada");
    }

    // CU23 paso 7: ficha de la aseguradora con sus pólizas (solo lectura).
    public function show($id)
    {
        $aseguradora = Aseguradora::findOrFail($id);

        $polizas = $aseguradora->seguros()
            ->with("paciente.persona")
            ->orderByDesc("vigente_hasta")
            ->orderBy("numero_poliza")
            ->get();

        return view("aseguradoras.show", compact("aseguradora", "polizas"));
    }

    // CU23 A1: modificar una aseguradora. El id viaja oculto en el formulario.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $aseguradora = Aseguradora::findOrFail($request->input("id"));

        // CU23 A1: las mismas validaciones que al registrar, sin contar a la propia aseguradora.
        $this->limpiarNombre($request);
        $this->limpiarNitYTelefono($request);
        $datos = $request->validate($this->reglas($aseguradora->id), $this->mensajes());

        $anteriores = $aseguradora->only(self::CAMPOS);

        try {
            DB::transaction(function () use ($aseguradora, $datos, $anteriores, $request) {
                $aseguradora->update($datos);

                Bitacora::registrar("MODIFICAR_ASEGURADORA", "catalogos", $request->user()->id, true, "aseguradoras", $aseguradora->id, "WEB", $anteriores, $aseguradora->only(self::CAMPOS));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return redirect()->route("aseguradoras.listar")->with("exito", "Aseguradora actualizada");
    }

    // CU23 A2: desactivar (RN11: no se borra). Se permite aunque tenga pólizas activas: el
    // mensaje dice cuántas son y que ya no sirven para citas nuevas (RN20).
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $aseguradora = Aseguradora::findOrFail($request->input("id"));

        $activas = $aseguradora->seguros()->where("estado", "ACTIVO")->count();

        $this->cambiarEstado($aseguradora, "INACTIVO", "DESACTIVAR_ASEGURADORA", $request, ["polizas_activas" => $activas]);

        $respuesta = redirect()->route("aseguradoras.listar")->with("exito", "Aseguradora desactivada");

        return $activas > 0
            ? $respuesta->with("aviso", "La aseguradora tiene {$activas} póliza(s) activa(s): ya no sirven para programar citas nuevas mientras esté inactiva.")
            : $respuesta;
    }

    // CU23 A3: reactivar una aseguradora inactiva. El id viaja oculto en el formulario.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $aseguradora = Aseguradora::findOrFail($request->input("id"));

        $this->cambiarEstado($aseguradora, "ACTIVO", "ACTIVAR_ASEGURADORA", $request);

        return redirect()->route("aseguradoras.listar")->with("exito", "Aseguradora activada");
    }

    // CU23 A2 / A3: cambia el estado y lo registra en la bitácora ($extra: datos nuevos adicionales,
    // como las pólizas activas al desactivar).
    private function cambiarEstado(Aseguradora $aseguradora, string $estado, string $accion, Request $request, array $extra = []): void
    {
        DB::transaction(function () use ($aseguradora, $estado, $accion, $request, $extra) {
            $anterior = $aseguradora->estado;
            $aseguradora->update(["estado" => $estado]);

            Bitacora::registrar($accion, "catalogos", $request->user()->id, true, "aseguradoras", $aseguradora->id, "WEB", ["estado" => $anterior], ["estado" => $estado] + $extra);
        });
    }

    // CU23 paso 4: sin espacios en los extremos y un solo espacio entre palabras.
    private function limpiarNombre(Request $request): void
    {
        if (is_string($request->input("nombre"))) {
            $request->merge(["nombre" => preg_replace("/\s+/u", " ", trim($request->input("nombre")))]);
        }
    }

    // CU23 paso 4: NIT y teléfono sin espacios en los extremos (el teléfono vacío queda null).
    private function limpiarNitYTelefono(Request $request): void
    {
        if (is_string($request->input("nit"))) {
            $request->merge(["nit" => trim($request->input("nit"))]);
        }
        if (is_string($request->input("telefono"))) {
            $request->merge(["telefono" => trim($request->input("telefono")) ?: null]);
        }
    }

    // CU23 paso 4: si la base rechaza el nombre (ux_aseguradoras_nombre) o el NIT (aseguradoras_nit_unique)
    // por estar repetidos (23505), se muestra el mismo mensaje que la validación. Cualquier otro error sigue su curso.
    private function nombreDuplicado(QueryException $e, Request $request)
    {
        if (($e->errorInfo[0] ?? null) === "23505") {
            if (str_contains($e->getMessage(), "ux_aseguradoras_nombre")) {
                return back()->withErrors(["nombre" => self::NOMBRE_REPETIDO])->withInput();
            }
            if (str_contains($e->getMessage(), "aseguradoras_nit_unique")) {
                return back()->withErrors(["nit" => self::NIT_REPETIDO])->withInput();
            }
        }

        throw $e;
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:aseguradoras,id"]];
    }

    // CU23 paso 4: reglas de los datos. Al modificar, $idIgnorar es la propia aseguradora.
    private function reglas(?int $idIgnorar = null): array
    {
        return [
            "nombre" => [
                "bail", "required", "string", "max:255",
                // ux_aseguradoras_nombre: único sin importar mayúsculas ni espacios.
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    $existe = Aseguradora::whereRaw("lower(regexp_replace(btrim(nombre), '\\s+', ' ', 'g')) = lower(?)", [$valor])
                        ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
                        ->exists();
                    if ($existe) {
                        $fallar(self::NOMBRE_REPETIDO);
                    }
                },
            ],
            "nit" => ["bail", "required", "string", "max:30", "regex:/^\d+$/", Rule::unique("aseguradoras", "nit")->ignore($idIgnorar)],
            "telefono" => ["nullable", "string", "regex:" . Persona::FORMATO_TELEFONO],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la aseguradora.",
            "id.integer" => "La aseguradora indicada no es válida.",
            "id.exists" => "La aseguradora indicada no existe.",
            "nombre.required" => "El campo Nombre es obligatorio.",
            "nombre.string" => "El nombre debe ser un texto.",
            "nombre.max" => "El nombre no puede superar los 255 caracteres.",
            "nit.required" => "El campo NIT es obligatorio.",
            "nit.string" => "El NIT debe ser un texto.",
            "nit.max" => "El NIT no puede superar los 30 dígitos.",
            "nit.regex" => "El NIT solo puede tener dígitos.",
            "nit.unique" => self::NIT_REPETIDO,
            "telefono.string" => "El teléfono debe ser un texto.",
            "telefono.regex" => "El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).",
        ];
    }

    // Escapa los comodines de LIKE que escriba el usuario.
    private function escaparLike(string $texto): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $texto);
    }
}
