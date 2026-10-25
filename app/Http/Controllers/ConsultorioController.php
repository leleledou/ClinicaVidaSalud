<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Consultorio;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CU08 Gestionar consultorios. Requiere el permiso consultorios.gestionar.
 * No hay borrado físico (RN11): desactivar cambia el estado a INACTIVO.
 */
class ConsultorioController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["nombre", "ubicacion", "estado"];

    private const NOMBRE_REPETIDO = "Ya existe un consultorio con ese nombre";

    // CU08 pasos 1-2: lista ordenada por nombre, con la cantidad de horarios ACTIVOS asignados.
    public function index()
    {
        $consultorios = Consultorio::withCount([
                "horarios as horarios_activos_count" => fn ($consulta) => $consulta->where("estado", "ACTIVO"),
            ])
            ->orderBy("nombre")
            ->get();

        return view("consultorios.index", compact("consultorios"));
    }

    // CU08 pasos 3 a 5: registrar un consultorio.
    public function store(Request $request)
    {
        // CU08 paso 4: limpiar el nombre y validar (incluye el nombre normalizado único).
        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas(), $this->mensajes());

        // CU08 paso 5: guardar como ACTIVO y registrar en la bitácora.
        $datos["estado"] = "ACTIVO";

        try {
            DB::transaction(function () use ($datos, $request) {
                $consultorio = Consultorio::create($datos);

                Bitacora::registrar("CREAR_CONSULTORIO", "catalogos", $request->user()->id, true, "consultorios", $consultorio->id, "WEB", null, $consultorio->only(self::CAMPOS));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return redirect()->route("consultorios.listar")->with("exito", "Consultorio registrado");
    }

    // Ficha del consultorio con sus horarios.
    public function show($id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $consultorio = Consultorio::findOrFail($id);

        $horarios = $consultorio->horarios()
            ->join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
            ->select("horarios_atencion.*", "medicos.codigo as medico_codigo", "personas.nombres", "personas.apellido_paterno",
                "personas.apellido_materno", "especialidades.nombre as especialidad")
            ->orderBy("horarios_atencion.dia_semana")
            ->orderBy("horarios_atencion.hora_inicio")
            ->get();

        return view("consultorios.show", compact("consultorio", "horarios"));
    }

    // Modificar un consultorio. El id viaja oculto en el formulario.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $consultorio = Consultorio::findOrFail($request->input("id"));

        // Las mismas validaciones que al registrar, sin contar al propio consultorio.
        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas($consultorio->id), $this->mensajes());

        $anteriores = $consultorio->only(self::CAMPOS);

        try {
            DB::transaction(function () use ($consultorio, $datos, $anteriores, $request) {
                $consultorio->update($datos);

                Bitacora::registrar("MODIFICAR_CONSULTORIO", "catalogos", $request->user()->id, true, "consultorios", $consultorio->id, "WEB", $anteriores, $consultorio->only(self::CAMPOS));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e, $request);
        }

        return redirect()->route("consultorios.listar")->with("exito", "Consultorio actualizado");
    }

    // CU08 A1: desactivar (RN11: no se borra). El id viaja oculto en el formulario.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $consultorio = Consultorio::findOrFail($request->input("id"));

        // CU08 A1: no se desactiva si tiene horarios ACTIVOS vigentes (sin fin o que terminan hoy o después).
        $vigentes = $consultorio->horarios()
            ->where("estado", "ACTIVO")
            ->where(fn ($consulta) => $consulta->whereNull("vigente_hasta")->orWhere("vigente_hasta", ">=", today()))
            ->count();

        if ($vigentes > 0) {
            return redirect()->route("consultorios.listar")
                ->withErrors(["operacion" => "El consultorio tiene {$vigentes} horario(s) vigente(s). Trasládelos a otro consultorio antes de desactivarlo."]);
        }

        $this->cambiarEstado($consultorio, "INACTIVO", "DESACTIVAR_CONSULTORIO", $request);

        return redirect()->route("consultorios.listar")->with("exito", "Consultorio desactivado");
    }

    // Activar un consultorio inactivo. El id viaja oculto en el formulario.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $consultorio = Consultorio::findOrFail($request->input("id"));

        $this->cambiarEstado($consultorio, "ACTIVO", "ACTIVAR_CONSULTORIO", $request);

        return redirect()->route("consultorios.listar")->with("exito", "Consultorio activado");
    }

    // CU08 A1 / activar: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(Consultorio $consultorio, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($consultorio, $estado, $accion, $request) {
            $anterior = $consultorio->estado;
            $consultorio->update(["estado" => $estado]);

            Bitacora::registrar($accion, "catalogos", $request->user()->id, true, "consultorios", $consultorio->id, "WEB", ["estado" => $anterior], ["estado" => $estado]);
        });
    }

    // CU08 paso 4: sin espacios en los extremos y un solo espacio entre palabras.
    private function limpiarNombre(Request $request): void
    {
        if (is_string($request->input("nombre"))) {
            $request->merge(["nombre" => preg_replace("/\s+/u", " ", trim($request->input("nombre")))]);
        }
    }

    // CU08 paso 4: si la base rechaza el nombre por el índice único (23505), se muestra
    // el mismo mensaje que la validación. Cualquier otro error sigue su curso (página de error).
    private function nombreDuplicado(QueryException $e, Request $request)
    {
        if (($e->errorInfo[0] ?? null) === "23505" && str_contains($e->getMessage(), "ux_consultorios_nombre")) {
            return back()->withErrors(["nombre" => self::NOMBRE_REPETIDO])->withInput();
        }

        throw $e;
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:consultorios,id"]];
    }

    // CU08 pasos 3 y 4: reglas de los datos. Al modificar, $idIgnorar es el propio consultorio.
    private function reglas(?int $idIgnorar = null): array
    {
        return [
            "nombre" => [
                "bail", "required", "string", "max:255",
                // CU08 paso 4: nombre normalizado único («c-101 » y «C-101» son el mismo).
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    $existe = Consultorio::whereRaw("fn_normalizar_texto(nombre) = fn_normalizar_texto(?)", [$valor])
                        ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
                        ->exists();
                    if ($existe) {
                        $fallar(self::NOMBRE_REPETIDO);
                    }
                },
            ],
            "ubicacion" => ["required", "string", "max:255"],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el consultorio.",
            "id.integer" => "El consultorio indicado no es válido.",
            "id.exists" => "El consultorio indicado no existe.",
            "nombre.required" => "El campo Nombre es obligatorio.",
            "nombre.string" => "El nombre debe ser un texto.",
            "nombre.max" => "El nombre no puede superar los 255 caracteres.",
            "ubicacion.required" => "El campo Ubicación es obligatorio.",
            "ubicacion.string" => "La ubicación debe ser un texto.",
            "ubicacion.max" => "La ubicación no puede superar los 255 caracteres.",
        ];
    }
}
