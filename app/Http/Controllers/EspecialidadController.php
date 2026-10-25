<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Especialidad;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CU06 Gestionar especialidades (RF04). Requiere el permiso especialidades.gestionar.
 * No hay borrado físico (RN11): desactivar cambia el estado a INACTIVO.
 */
class EspecialidadController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["nombre", "descripcion", "edad_minima", "edad_maxima", "sexo_permitido", "duracion_sugerida_min", "estado"];

    private const NOMBRE_REPETIDO = "Ya existe una especialidad con ese nombre";

    // CU06 pasos 1-2: lista de especialidades ordenada por nombre, con la cantidad de
    // médicos que la ejercen (asignaciones ACTIVAS).
    public function index()
    {
        $especialidades = Especialidad::withCount([
                "medicoEspecialidades as medicos_count" => fn ($consulta) => $consulta->where("estado", "ACTIVO"),
            ])
            ->orderBy("nombre")
            ->get();

        return view("especialidades.index", compact("especialidades"));
    }

    // CU06 pasos 3 a 7: registrar una especialidad.
    public function store(Request $request)
    {
        // CU06 paso 5: limpiar el nombre antes de validar.
        $this->limpiarNombre($request);

        // CU06 pasos 5 y 6: validar (incluye el nombre normalizado único).
        $datos = $request->validate($this->reglas(), $this->mensajes());

        // CU06 paso 7: guardar como ACTIVA y registrar en la bitácora.
        $datos["estado"] = "ACTIVO";

        try {
            DB::transaction(function () use ($datos, $request) {
                $especialidad = Especialidad::create($datos);

                Bitacora::registrar("CREAR_ESPECIALIDAD", "catalogos", $request->user()->id, true, "especialidades", $especialidad->id, "WEB", null, $especialidad->only(self::CAMPOS));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return redirect()->route("especialidades.listar")->with("exito", "Especialidad registrada");
    }

    // Ficha con todos los datos y los médicos que la ejercen.
    public function show($id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $especialidad = Especialidad::findOrFail($id);

        $medicos = $especialidad->medicoEspecialidades()
            ->join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->select("medicos.codigo", "personas.nombres", "personas.apellido_paterno", "personas.apellido_materno",
                "medicos_especialidades.es_principal", "medicos_especialidades.estado")
            ->orderBy("personas.apellido_paterno")
            ->orderBy("personas.nombres")
            ->get();

        return view("especialidades.show", compact("especialidad", "medicos"));
    }

    // CU06 A1: modificar una especialidad. El id viaja oculto en el formulario.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $especialidad = Especialidad::findOrFail($request->input("id"));

        // CU06 A1: las mismas validaciones que al registrar, sin contar a la propia especialidad.
        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas($especialidad->id), $this->mensajes());

        $anteriores = $especialidad->only(self::CAMPOS);

        try {
            DB::transaction(function () use ($especialidad, $datos, $anteriores, $request) {
                $especialidad->update($datos);

                Bitacora::registrar("MODIFICAR_ESPECIALIDAD", "catalogos", $request->user()->id, true, "especialidades", $especialidad->id, "WEB", $anteriores, $especialidad->only(self::CAMPOS));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return redirect()->route("especialidades.listar")->with("exito", "Especialidad actualizada");
    }

    // CU06 A2: desactivar (RN11: no se borra). El id viaja oculto en el formulario.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $especialidad = Especialidad::findOrFail($request->input("id"));

        $this->cambiarEstado($especialidad, "INACTIVO", "DESACTIVAR_ESPECIALIDAD", $request);

        return redirect()->route("especialidades.listar")->with("exito", "Especialidad desactivada");
    }

    // Activar una especialidad inactiva. El id viaja oculto en el formulario.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $especialidad = Especialidad::findOrFail($request->input("id"));

        $this->cambiarEstado($especialidad, "ACTIVO", "ACTIVAR_ESPECIALIDAD", $request);

        return redirect()->route("especialidades.listar")->with("exito", "Especialidad activada");
    }

    // CU06 A2 / activar: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(Especialidad $especialidad, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($especialidad, $estado, $accion, $request) {
            $anterior = $especialidad->estado;
            $especialidad->update(["estado" => $estado]);

            Bitacora::registrar($accion, "catalogos", $request->user()->id, true, "especialidades", $especialidad->id, "WEB", ["estado" => $anterior], ["estado" => $estado]);
        });
    }

    // CU06 paso 5: sin espacios en los extremos y un solo espacio entre palabras.
    private function limpiarNombre(Request $request): void
    {
        if (is_string($request->input("nombre"))) {
            $request->merge(["nombre" => preg_replace("/\s+/u", " ", trim($request->input("nombre")))]);
        }
    }

    // CU06 paso 5: si la base rechaza el nombre por el índice único (23505), se muestra
    // el mismo mensaje que la validación. Cualquier otro error sigue su curso (página de error).
    private function nombreDuplicado(QueryException $e, Request $request)
    {
        if (($e->errorInfo[0] ?? null) === "23505" && str_contains($e->getMessage(), "ux_especialidades_nombre")) {
            return back()->withErrors(["nombre" => self::NOMBRE_REPETIDO])->withInput();
        }

        throw $e;
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:especialidades,id"]];
    }

    // CU06 pasos 5 y 6: reglas de los datos. Al modificar, $idIgnorar es la propia especialidad.
    private function reglas(?int $idIgnorar = null): array
    {
        return [
            "nombre" => [
                "bail", "required", "string", "max:255",
                // CU06 paso 5: nombre normalizado único (sin tildes, mayúsculas ni espacios repetidos).
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    $existe = Especialidad::whereRaw("fn_normalizar_texto(nombre) = fn_normalizar_texto(?)", [$valor])
                        ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
                        ->exists();
                    if ($existe) {
                        $fallar(self::NOMBRE_REPETIDO);
                    }
                },
            ],
            "descripcion" => ["nullable", "string", "max:2000"],
            // CU06 paso 6: edades enteras entre 0 y 120, la mínima no supera a la máxima.
            "edad_minima" => [
                "bail", "nullable", "integer", "between:0,120",
                // Solo se compara si las dos edades fueron indicadas (lte fallaría con la máxima vacía).
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    $maxima = request()->input("edad_maxima");
                    if (is_numeric($maxima) && (int) $valor > (int) $maxima) {
                        $fallar("La edad mínima no puede ser mayor que la edad máxima");
                    }
                },
            ],
            "edad_maxima" => ["nullable", "integer", "between:0,120"],
            // CU06 paso 6: sexo nulo (ambos), M o F.
            "sexo_permitido" => ["nullable", "in:M,F"],
            // CU06 paso 6: duración entera entre 10 y 120 minutos.
            "duracion_sugerida_min" => ["required", "integer", "between:10,120"],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la especialidad.",
            "id.integer" => "La especialidad indicada no es válida.",
            "id.exists" => "La especialidad indicada no existe.",
            "nombre.required" => "El campo Nombre es obligatorio.",
            "nombre.string" => "El nombre debe ser un texto.",
            "nombre.max" => "El nombre no puede superar los 255 caracteres.",
            "descripcion.string" => "La descripción debe ser un texto.",
            "descripcion.max" => "La descripción no puede superar los 2000 caracteres.",
            "edad_minima.integer" => "La edad mínima debe ser un número entero.",
            "edad_minima.between" => "La edad mínima debe estar entre 0 y 120 años.",
            "edad_maxima.integer" => "La edad máxima debe ser un número entero.",
            "edad_maxima.between" => "La edad máxima debe estar entre 0 y 120 años.",
            "sexo_permitido.in" => "El sexo permitido debe ser Ambos, Solo mujeres o Solo hombres.",
            "duracion_sugerida_min.required" => "El campo Duración sugerida es obligatorio.",
            "duracion_sugerida_min.integer" => "La duración sugerida debe ser un número entero de minutos.",
            "duracion_sugerida_min.between" => "La duración sugerida debe estar entre 10 y 120 minutos.",
        ];
    }
}
