<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Permiso;
use App\Models\Rol;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CU05 Gestionar roles y permisos (RF01, RN12). Requiere el permiso roles.gestionar.
 * La asociación rol-permiso (roles_permisos) se guarda aquí con sync().
 * No hay borrado físico (RN11): desactivar cambia el estado a INACTIVO y el rol
 * pierde sus permisos (Usuario::tienePermiso, CU05 A1).
 */
class RolController extends Controller
{
    // Roles que usa el sistema (perfiles de RN25 y seeders): no se renombran.
    public const ROLES_DEL_SISTEMA = ["ADMINISTRADOR", "RECEPCIONISTA", "MEDICO", "PACIENTE"];

    // Permisos sin los cuales nadie podría volver a administrar roles y usuarios.
    private const PERMISOS_PROTEGIDOS = ["roles.gestionar", "usuarios.gestionar"];

    // CU05 paso 4: títulos legibles de los módulos (permisos.modulo).
    public const MODULOS = [
        "seguridad" => "Seguridad",
        "catalogos" => "Catálogos",
        "agenda" => "Agenda",
        "pacientes" => "Pacientes",
        "citas" => "Citas",
        "clinica" => "Atención clínica",
        "laboratorio" => "Laboratorio",
        "notificaciones" => "Notificaciones",
        "reportes" => "Reportes",
        "voz" => "Voz",
    ];

    private const NOMBRE_REPETIDO = "Ya existe un rol con ese nombre";
    private const SIN_AUTOBLOQUEO = "No puede quitarse a sí mismo el acceso a la gestión de roles y usuarios.";

    // CU05 paso 2: lista de roles con la cantidad de usuarios y de permisos.
    public function index()
    {
        $roles = Rol::withCount(["usuarios", "permisos"])->orderBy("nombre")->get();

        return view("roles.index", compact("roles"));
    }

    // CU05 paso 3: registrar un rol (los permisos se marcan después en su ficha).
    public function store(Request $request)
    {
        // CU05 paso 6: nombre en MAYÚSCULAS y sin espacios extra, y único.
        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas(), $this->mensajes());
        $datos["estado"] = "ACTIVO";

        try {
            $rol = DB::transaction(function () use ($datos, $request) {
                $rol = Rol::create($datos);

                // CU05 paso 7
                Bitacora::registrar("CREAR_ROL", "seguridad", $request->user()->id, true, "roles", $rol->id, "WEB", null, $this->foto($rol));

                return $rol;
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e);
        }

        return redirect()->route("roles.mostrar", $rol->id)->with("exito", "Rol registrado: marque sus permisos y presione «Guardar permisos».");
    }

    // CU05 paso 4: ficha del rol con los permisos agrupados por módulo.
    public function show($id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $rol = Rol::withCount("usuarios")->with("permisos")->findOrFail($id);

        // Agrupados en el orden de MODULOS (los módulos desconocidos van al final con su nombre tal cual).
        $permisos = Permiso::orderBy("nombre")->get()
            ->groupBy("modulo")
            ->sortBy(fn ($grupo, $modulo) => array_search($modulo, array_keys(self::MODULOS)) === false ? 999 : array_search($modulo, array_keys(self::MODULOS)));

        $asignados = $rol->permisos->pluck("id")->all();
        $esRolPropio = $rol->id === request()->user()->rol_id;

        return view("roles.show", [
            "rol" => $rol,
            "permisos" => $permisos,
            "asignados" => $asignados,
            "modulos" => self::MODULOS,
            "esRolPropio" => $esRolPropio,
            "protegidos" => self::PERMISOS_PROTEGIDOS,
        ]);
    }

    // CU05 pasos 5 a 7: modificar el nombre y la descripción (formulario de la lista) o los
    // permisos (ficha, «Guardar permisos»). El id viaja oculto en el formulario.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $rol = Rol::with("permisos")->findOrFail($request->input("id"));
        $anteriores = $this->foto($rol);

        // CU05 pasos 5 y 6: guardar permisos.
        if ($request->has("guardar_permisos")) {
            $datos = $request->validate([
                "permisos" => ["nullable", "array"],
                "permisos.*" => ["integer", "exists:permisos,id"],
            ], $this->mensajes());
            $ids = array_values(array_unique(array_map("intval", $datos["permisos"] ?? [])));

            // Protección: el propio rol no puede perder roles.gestionar ni usuarios.gestionar.
            if ($rol->id === $request->user()->rol_id) {
                $codigosNuevos = Permiso::whereIn("id", $ids)->pluck("codigo")->all();
                if (array_diff(self::PERMISOS_PROTEGIDOS, $codigosNuevos)) {
                    return back()->withErrors(["operacion" => self::SIN_AUTOBLOQUEO]);
                }
            }

            // CU05 paso 6: sincronizar en UNA transacción, con su registro en la bitácora.
            DB::transaction(function () use ($rol, $ids, $anteriores, $request) {
                $rol->permisos()->sync($ids);

                Bitacora::registrar("MODIFICAR_ROL", "seguridad", $request->user()->id, true, "roles", $rol->id, "WEB", $anteriores, $this->foto($rol));
            });

            // CU05 paso 7: tienePermiso() se evalúa en cada petición.
            return redirect()->route("roles.mostrar", $rol->id)
                ->with("exito", "Permisos actualizados: los usuarios del rol los obtendrán en su siguiente petición.");
        }

        // Nombre y descripción.
        $this->limpiarNombre($request);
        $datos = $request->validate($this->reglas($rol->id), $this->mensajes());

        // Los roles del sistema no se renombran: RN25 y los perfiles dependen de su nombre.
        if (in_array($rol->nombre, self::ROLES_DEL_SISTEMA, true) && $datos["nombre"] !== $rol->nombre) {
            return back()->withErrors(["nombre" => "El rol {$rol->nombre} es del sistema: no se puede cambiar su nombre."])->withInput();
        }

        try {
            DB::transaction(function () use ($rol, $datos, $anteriores, $request) {
                $rol->update($datos);

                Bitacora::registrar("MODIFICAR_ROL", "seguridad", $request->user()->id, true, "roles", $rol->id, "WEB", $anteriores, $this->foto($rol));
            });
        } catch (QueryException $e) {
            return $this->nombreDuplicado($e);
        }

        return redirect()->route("roles.listar")->with("exito", "Rol actualizado");
    }

    // CU05 A1: desactivar (RN11: no se borra). Sus usuarios pierden los permisos del rol.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $rol = Rol::findOrFail($request->input("id"));

        // Protección: no se desactiva el rol del propio usuario que está operando.
        if ($rol->id === $request->user()->rol_id) {
            return back()->withErrors(["operacion" => self::SIN_AUTOBLOQUEO]);
        }

        $this->cambiarEstado($rol, "INACTIVO", "DESACTIVAR_ROL", $request);

        return back()->with("exito", "Rol desactivado: sus usuarios pierden los permisos del rol.");
    }

    // Activar un rol inactivo. El id viaja oculto en el formulario.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $rol = Rol::findOrFail($request->input("id"));

        $this->cambiarEstado($rol, "ACTIVO", "ACTIVAR_ROL", $request);

        return back()->with("exito", "Rol activado");
    }

    // CU05 A1 / activar: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(Rol $rol, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($rol, $estado, $accion, $request) {
            $anterior = $rol->estado;
            $rol->update(["estado" => $estado]);

            Bitacora::registrar($accion, "seguridad", $request->user()->id, true, "roles", $rol->id, "WEB", ["estado" => $anterior], ["estado" => $estado]);
        });
    }

    // CU05 paso 7: nombre, descripción y códigos de permiso, para la bitácora.
    private function foto(Rol $rol): array
    {
        $rol->refresh()->load("permisos");

        return [
            "nombre" => $rol->nombre,
            "descripcion" => $rol->descripcion,
            "permisos" => $rol->permisos->pluck("codigo")->sort()->values()->all(),
        ];
    }

    // CU05 paso 6: MAYÚSCULAS, sin espacios en los extremos y un solo espacio entre palabras.
    private function limpiarNombre(Request $request): void
    {
        if (is_string($request->input("nombre"))) {
            $request->merge(["nombre" => mb_strtoupper(preg_replace("/\s+/u", " ", trim($request->input("nombre"))))]);
        }
    }

    // CU05 paso 6: si la base rechaza el nombre (23505 en ux_roles_nombre), el mismo mensaje.
    // Cualquier otro error sigue su curso (página de error sin detalles).
    private function nombreDuplicado(QueryException $e)
    {
        if (($e->errorInfo[0] ?? null) === "23505" && str_contains($e->getMessage(), "ux_roles_nombre")) {
            return back()->withErrors(["nombre" => self::NOMBRE_REPETIDO])->withInput();
        }

        throw $e;
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:roles,id"]];
    }

    // CU05 paso 6: reglas del nombre y la descripción. Al modificar, $idIgnorar es el propio rol.
    private function reglas(?int $idIgnorar = null): array
    {
        return [
            "nombre" => [
                "bail", "required", "string", "max:255",
                // Único sin distinguir mayúsculas ni espacios (como ux_roles_nombre).
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    $existe = Rol::whereRaw("lower(btrim(nombre)) = lower(btrim(?))", [$valor])
                        ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
                        ->exists();
                    if ($existe) {
                        $fallar(self::NOMBRE_REPETIDO);
                    }
                },
            ],
            "descripcion" => ["nullable", "string", "max:2000"],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el rol.",
            "id.integer" => "El rol indicado no es válido.",
            "id.exists" => "El rol indicado no existe.",
            "nombre.required" => "El campo Nombre es obligatorio.",
            "nombre.string" => "El nombre debe ser un texto.",
            "nombre.max" => "El nombre no puede superar los 255 caracteres.",
            "descripcion.max" => "La descripción no puede superar los 2000 caracteres.",
            "permisos.array" => "Los permisos enviados no son válidos.",
            "permisos.*.integer" => "Uno de los permisos enviados no es válido.",
            "permisos.*.exists" => "Uno de los permisos enviados no existe.",
        ];
    }
}
