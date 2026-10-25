<?php

namespace App\Http\Controllers;

use App\Models\Administrador;
use App\Models\Bitacora;
use App\Models\ConfiguracionSistema;
use App\Models\Persona;
use App\Models\Recepcionista;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * CU04 Gestionar usuarios (cuentas de acceso). Requiere el permiso usuarios.gestionar.
 * RN25: un rol profesional exige el perfil correspondiente ACTIVO de la misma persona.
 * No hay borrado físico (RN11): desactivar cambia el estado a INACTIVO.
 */
class UsuarioController extends Controller
{
    // Roles del personal: documento obligatorio y mayor de edad (CU04 paso 4).
    private const ROLES_PERSONAL = ["ADMINISTRADOR", "RECEPCIONISTA", "MEDICO"];

    // RN25: perfil que exige cada rol y su texto en los mensajes.
    private const PERFILES = [
        "MEDICO" => ["medico", "médico"],
        "PACIENTE" => ["paciente", "paciente"],
        "RECEPCIONISTA" => ["recepcionista", "recepcionista"],
        "ADMINISTRADOR" => ["administrador", "administrador"],
    ];

    public const TURNOS = ["MANANA" => "Mañana", "TARDE" => "Tarde", "COMPLETO" => "Completo"];

    private const CAMPOS_PERSONA = ["nombres", "apellido_paterno", "apellido_materno", "fecha_nacimiento", "sexo", "telefono", "correo", "direccion"];

    // CU04 paso 2: lista paginada con filtros por rol y estado y búsqueda por nombre, usuario o correo.
    public function index(Request $request)
    {
        $texto = trim((string) $request->query("q", ""));
        $rolId = ctype_digit((string) $request->query("rol_id")) ? (int) $request->query("rol_id") : null;
        $estado = in_array($request->query("estado"), ["ACTIVO", "INACTIVO"], true) ? $request->query("estado") : null;

        $usuarios = Usuario::with(["persona", "rol"])
            ->join("personas", "personas.id", "=", "usuarios.persona_id")
            ->select("usuarios.*")
            // Nombres sin tildes ni mayúsculas (fn_normalizar_texto); usuario y correo sin mayúsculas. Con parámetros.
            ->when($texto !== "", function ($consulta) use ($texto) {
                $patron = "%" . $this->escaparLike($texto) . "%";
                $consulta->where(fn ($condicion) => $condicion
                    ->whereRaw("fn_normalizar_texto(personas.nombres || ' ' || personas.apellido_paterno || ' ' || coalesce(personas.apellido_materno, '')) LIKE fn_normalizar_texto(?)", [$patron])
                    ->orWhereRaw("lower(usuarios.nombre_usuario) LIKE lower(?)", [$patron])
                    ->orWhereRaw("lower(usuarios.correo) LIKE lower(?)", [$patron]));
            })
            ->when($rolId, fn ($consulta) => $consulta->where("usuarios.rol_id", $rolId))
            ->when($estado, fn ($consulta) => $consulta->where("usuarios.estado", $estado))
            ->orderBy("personas.apellido_paterno")
            ->orderBy("personas.nombres")
            ->paginate(15)
            ->withQueryString();

        // Si una modificación volvió con errores, la cuenta que se estaba modificando (para sus perfiles).
        $editado = ctype_digit((string) $request->old("id"))
            ? Usuario::with(["persona.medico", "persona.paciente", "persona.recepcionista", "persona.administrador"])->find($request->old("id"))
            : null;

        return view("usuarios.index", [
            "usuarios" => $usuarios,
            "roles" => Rol::orderBy("nombre")->get(),
            "texto" => $texto,
            "rolId" => $rolId,
            "estado" => $estado,
            "editado" => $editado,
            "turnos" => self::TURNOS,
            "edadMayoria" => $this->edadMayoria(),
        ]);
    }

    // CU04 pasos 3 a 8: crear una cuenta de acceso.
    public function store(Request $request)
    {
        $this->normalizarEntrada($request);

        // CU04 paso 5: el rol (solo ACTIVOS) define qué se exige a la persona.
        $request->validate($this->reglaRol(), $this->mensajes());
        $rol = Rol::find($request->input("rol_id"));
        $esPersonal = in_array($rol->nombre, self::ROLES_PERSONAL, true);

        // CU04 paso 4: documento (obligatorio para el personal) y búsqueda de la persona.
        $documento = $request->validate($this->reglasDocumento($esPersonal), $this->mensajes());
        $persona = empty($documento["numero_documento"])
            ? null
            : Persona::buscarPorDocumento($documento["tipo_documento"], $documento["numero_documento"], $documento["complemento"] ?? null);

        // CU04 paso 4: una persona tiene como máximo una cuenta (usuarios.persona_id es único).
        if ($persona?->usuario) {
            return back()->withErrors(["numero_documento" => "Esa persona ya tiene una cuenta de acceso (usuario «{$persona->usuario->nombre_usuario}»)"])->withInput();
        }

        // CU04 pasos 4 a 7: datos de la persona (solo si es nueva), del perfil y de la cuenta.
        $datos = $request->validate($this->reglas($rol, $persona, $persona === null && $esPersonal), $this->mensajes());

        // CU04 paso 4: una persona ya registrada que va a ser personal debe ser mayor de edad.
        if ($persona !== null && $esPersonal && ($error = $this->errorPersonal($persona))) {
            return back()->withErrors(["numero_documento" => $error])->withInput();
        }

        // CU04 paso 7 / RN25: el rol exige su perfil ACTIVO.
        if ($error = $this->errorPerfil($rol, $persona)) {
            return back()->withErrors(["rol_id" => $error])->withInput();
        }

        // CU04 paso 6: contraseña temporal (nunca se guarda en claro ni en la bitácora).
        $temporal = $this->contrasenaTemporal();

        try {
            // CU04 paso 8: persona, perfil y cuenta en UNA transacción.
            $usuario = DB::transaction(function () use ($persona, $documento, $datos, $rol, $temporal, $request) {
                $persona ??= Persona::create(array_merge($documento, Arr::only($datos, self::CAMPOS_PERSONA)));

                $this->crearPerfil($rol, $persona, $datos);

                $usuario = Usuario::create([
                    "persona_id" => $persona->id,
                    "rol_id" => $rol->id,
                    "nombre_usuario" => $datos["nombre_usuario"],
                    "correo" => $datos["correo_acceso"],
                    "password" => Hash::make($temporal),
                    "estado" => "ACTIVO",
                    "debe_cambiar_password" => true,
                ]);

                Bitacora::registrar("CREAR_USUARIO", "seguridad", $request->user()->id, true, "usuarios", $usuario->id, "WEB", null, $this->foto($usuario));

                return $usuario;
            });
        } catch (QueryException $e) {
            return $this->errorUnicidad($e);
        }

        return $this->conTemporal(
            redirect()->route("usuarios.mostrar", $usuario->id)->with("exito", "Usuario registrado ({$usuario->nombre_usuario})"),
            $usuario,
            $temporal
        );
    }

    // Ficha de la cuenta: datos, persona, perfiles y sus últimas 20 acciones.
    public function show(Request $request, $id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $usuario = Usuario::with(["rol", "persona.medico", "persona.paciente", "persona.recepcionista", "persona.administrador"])->findOrFail($id);

        $acciones = Bitacora::where("usuario_id", $usuario->id)->orderByDesc("fecha_hora")->orderByDesc("id")->limit(20)->get();

        // CU04 paso 8 / A2: la contraseña temporal se muestra UNA sola vez (llega cifrada en la sesión flash).
        $temporal = null;
        if ($cifrada = $request->session()->get("password_temporal")) {
            try {
                $temporal = json_decode(Crypt::decryptString($cifrada), true);
            } catch (\Throwable) {
                $temporal = null;
            }
        }

        return view("usuarios.show", [
            "usuario" => $usuario,
            "acciones" => $acciones,
            "temporal" => ($temporal["usuario_id"] ?? null) === $usuario->id ? $temporal["password"] : null,
            "esPropio" => $usuario->id === $request->user()->id,
        ]);
    }

    // CU04 A1: modificar nombre de usuario, correo y rol. El id viaja oculto.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $usuario = Usuario::with(["rol", "persona.medico", "persona.paciente", "persona.recepcionista", "persona.administrador"])->findOrFail($request->input("id"));

        $request->validate($this->reglaRol($usuario->rol_id), $this->mensajes());
        $rol = Rol::find($request->input("rol_id"));

        // Protección: el usuario que opera no puede cambiarse su propio rol.
        if ($usuario->id === $request->user()->id && $rol->id !== $usuario->rol_id) {
            return back()->withErrors(["rol_id" => "No puede cambiar su propio rol."])->withInput();
        }

        // CU04 A1: mismas validaciones, sin contar a la propia cuenta.
        $datos = $request->validate($this->reglas($rol, $usuario->persona, false, $usuario->id), $this->mensajes());

        // CU04 A1: se vuelve a validar el rol con los perfiles (RN25) y, para el personal, la edad.
        if (in_array($rol->nombre, self::ROLES_PERSONAL, true) && ($error = $this->errorPersonal($usuario->persona))) {
            return back()->withErrors(["rol_id" => $error])->withInput();
        }
        if ($error = $this->errorPerfil($rol, $usuario->persona)) {
            return back()->withErrors(["rol_id" => $error])->withInput();
        }

        $anteriores = $this->foto($usuario);
        $rolAnterior = $usuario->rol->nombre;

        try {
            DB::transaction(function () use ($usuario, $datos, $rol, $anteriores, $rolAnterior, $request) {
                $this->crearPerfil($rol, $usuario->persona, $datos);

                $usuario->update([
                    "nombre_usuario" => $datos["nombre_usuario"],
                    "correo" => $datos["correo_acceso"],
                    "rol_id" => $rol->id,
                ]);

                Bitacora::registrar("MODIFICAR_USUARIO", "seguridad", $request->user()->id, true, "usuarios", $usuario->id, "WEB", $anteriores, $this->foto($usuario));

                // CU04 A1: el cambio de rol queda además como acción propia.
                if ($rol->nombre !== $rolAnterior) {
                    Bitacora::registrar("CAMBIO_ROL", "seguridad", $request->user()->id, true, "usuarios", $usuario->id, "WEB", ["rol" => $rolAnterior], ["rol" => $rol->nombre]);
                }
            });
        } catch (QueryException $e) {
            return $this->errorUnicidad($e);
        }

        return redirect()->route("usuarios.mostrar", $usuario->id)->with("exito", "Usuario actualizado");
    }

    // CU04 A3: desactivar (RN11: no se borra; conserva su bitácora y lo que registró). El id viaja oculto.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $usuario = Usuario::findOrFail($request->input("id"));

        // Protección: el usuario que opera no puede desactivarse a sí mismo.
        if ($usuario->id === $request->user()->id) {
            return back()->withErrors(["operacion" => "No puede desactivar su propia cuenta."]);
        }

        $this->cambiarEstado($usuario, "INACTIVO", "DESACTIVAR_USUARIO", $request);

        return back()->with("exito", "Usuario desactivado");
    }

    // CU04 A3: activar una cuenta inactiva (vuelve a validar RN25). El id viaja oculto.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $usuario = Usuario::with(["rol", "persona.medico", "persona.paciente", "persona.recepcionista", "persona.administrador"])->findOrFail($request->input("id"));

        if ($error = $this->errorPerfil($usuario->rol, $usuario->persona, false)) {
            return back()->withErrors(["operacion" => $error]);
        }

        $this->cambiarEstado($usuario, "ACTIVO", "ACTIVAR_USUARIO", $request);

        return back()->with("exito", "Usuario activado");
    }

    // CU04 A2: desbloquear la cuenta (bloqueo por intentos fallidos, CU01 A1).
    public function desbloquear(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $usuario = Usuario::findOrFail($request->input("id"));

        DB::transaction(function () use ($usuario, $request) {
            $anteriores = ["bloqueado_hasta" => $usuario->bloqueado_hasta?->format("Y-m-d H:i:s"), "intentos_fallidos" => $usuario->intentos_fallidos];
            $usuario->update(["bloqueado_hasta" => null, "intentos_fallidos" => 0]);

            Bitacora::registrar("DESBLOQUEAR_USUARIO", "seguridad", $request->user()->id, true, "usuarios", $usuario->id, "WEB", $anteriores, ["bloqueado_hasta" => null, "intentos_fallidos" => 0]);
        });

        return back()->with("exito", "Usuario desbloqueado");
    }

    // CU04 A2: restablecer la contraseña con una nueva temporal (cambio obligatorio al ingresar).
    public function restablecer(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $usuario = Usuario::findOrFail($request->input("id"));

        $temporal = $this->contrasenaTemporal();

        DB::transaction(function () use ($usuario, $temporal, $request) {
            // Al cambiar la contraseña, sus sesiones abiertas se cierran (middleware auth.session).
            $usuario->update(["password" => Hash::make($temporal), "debe_cambiar_password" => true]);

            // Sin la contraseña.
            Bitacora::registrar("RESTABLECER_PASSWORD", "seguridad", $request->user()->id, true, "usuarios", $usuario->id, "WEB", null, ["debe_cambiar_password" => true]);
        });

        return $this->conTemporal(
            redirect()->route("usuarios.mostrar", $usuario->id)->with("exito", "Contraseña restablecida"),
            $usuario,
            $temporal
        );
    }

    // CU04 A3 / activar: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(Usuario $usuario, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($usuario, $estado, $accion, $request) {
            $anterior = $usuario->estado;
            $usuario->update(["estado" => $estado]);

            Bitacora::registrar($accion, "seguridad", $request->user()->id, true, "usuarios", $usuario->id, "WEB", ["estado" => $anterior], ["estado" => $estado]);
        });
    }

    // CU04 paso 8 / A2: envía la contraseña temporal al correo de acceso y la deja, cifrada,
    // para mostrarla UNA sola vez en la ficha. No se escribe en la bitácora ni en logs propios.
    private function conTemporal($respuesta, Usuario $usuario, string $temporal)
    {
        try {
            Mail::raw(
                "Hola {$usuario->persona->nombres}:\n\n"
                . "Su cuenta de acceso a Clínica Vida Salud es «{$usuario->nombre_usuario}».\n"
                . "Contraseña temporal: {$temporal}\n\n"
                . "Deberá cambiarla la primera vez que ingrese.",
                fn ($mensaje) => $mensaje->to($usuario->correo)->subject("Clínica Vida Salud: contraseña temporal")
            );
        } catch (\Throwable $e) {
            // Solo se registra que falló (sin la contraseña); el administrador la entrega en persona.
            Log::warning("No se pudo enviar el correo con la contraseña temporal del usuario {$usuario->id}: " . get_class($e));
            $respuesta->with("aviso", "No se pudo enviar el correo: entregue la contraseña temporal en persona.");
        }

        return $respuesta->with("password_temporal", Crypt::encryptString(json_encode(["usuario_id" => $usuario->id, "password" => $temporal])));
    }

    // CU04 paso 6: 12 caracteres con al menos una mayúscula, una minúscula, un número y un
    // símbolo (política de CU03). Sin caracteres que se confunden (I, l, O, 0, 1).
    private function contrasenaTemporal(): string
    {
        $grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_+?'];
        $todos = implode('', $grupos);

        // Uno de cada grupo y el resto de cualquiera (random_int es criptográficamente seguro).
        $caracteres = array_map(fn ($grupo) => $grupo[random_int(0, strlen($grupo) - 1)], $grupos);
        while (count($caracteres) < 12) {
            $caracteres[] = $todos[random_int(0, strlen($todos) - 1)];
        }

        // Mezcla (Fisher-Yates) para que el orden de los grupos no sea predecible.
        for ($i = count($caracteres) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$caracteres[$i], $caracteres[$j]] = [$caracteres[$j], $caracteres[$i]];
        }

        return implode('', $caracteres);
    }

    // CU04 paso 5: crea el perfil de recepcionista o administrador si la persona no lo tiene.
    // El rol MEDICO no crea perfil (se registra en CU07) y PACIENTE tampoco (CU10).
    private function crearPerfil(Rol $rol, Persona $persona, array $datos): void
    {
        if ($rol->nombre === "RECEPCIONISTA" && ! $persona->recepcionista()->exists()) {
            Recepcionista::create([
                "persona_id" => $persona->id,
                "codigo" => $this->siguienteCodigo("recepcionistas", "REC"),
                "turno" => $datos["turno"],
                "fecha_ingreso" => $datos["fecha_ingreso"],
                "estado" => "ACTIVO",
            ]);
        }

        if ($rol->nombre === "ADMINISTRADOR" && ! $persona->administrador()->exists()) {
            Administrador::create([
                "persona_id" => $persona->id,
                "codigo" => $this->siguienteCodigo("administradores", "ADM"),
                "cargo" => $datos["cargo"],
                "estado" => "ACTIVO",
            ]);
        }
    }

    // CU04 paso 5: código REC-NNN / ADM-NNN siguiente. El candado de la transacción evita que
    // dos registros simultáneos tomen el mismo número. $tabla y $prefijo son constantes del código.
    private function siguienteCodigo(string $tabla, string $prefijo): string
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["{$tabla}.codigo"]);
        $desde = strlen($prefijo) + 2;
        $ultimo = DB::selectOne("SELECT coalesce(max(substring(codigo from {$desde})::int), 0) AS n FROM {$tabla} WHERE codigo ~ ?", ["^{$prefijo}-[0-9]+$"])->n;

        return sprintf("%s-%03d", $prefijo, $ultimo + 1);
    }

    // RN25: el rol exige su perfil ACTIVO. MEDICO y PACIENTE exigen que exista; RECEPCIONISTA y
    // ADMINISTRADOR solo fallan si existe y está INACTIVO (si no existe, se crea).
    // $puedeCrear = false al activar una cuenta (no se crean perfiles).
    private function errorPerfil(Rol $rol, ?Persona $persona, bool $puedeCrear = true): ?string
    {
        if (! isset(self::PERFILES[$rol->nombre])) {
            return null; // Otros roles (creados en CU05): no exigen perfil.
        }

        [$relacion, $texto] = self::PERFILES[$rol->nombre];
        $perfil = $persona?->{$relacion};
        $seCrea = $puedeCrear && in_array($rol->nombre, ["RECEPCIONISTA", "ADMINISTRADOR"], true);

        if (($perfil === null && ! $seCrea) || ($perfil !== null && $perfil->estado !== "ACTIVO")) {
            return "El rol {$rol->nombre} exige que la persona tenga el perfil de {$texto} ACTIVO";
        }

        return null;
    }

    // CU04 paso 4: el personal debe tener documento y ser mayor de edad.
    private function errorPersonal(Persona $persona): ?string
    {
        if (empty($persona->numero_documento)) {
            return "El personal de la clínica debe tener documento de identidad registrado.";
        }
        if ($persona->edad < $this->edadMayoria()) {
            return "El personal de la clínica debe ser mayor de edad ({$this->edadMayoria()} años o más).";
        }

        return null;
    }

    // Datos de la cuenta para la bitácora, SIN la contraseña.
    private function foto(Usuario $usuario): array
    {
        $usuario->refresh()->load("rol");

        return [
            "persona_id" => $usuario->persona_id,
            "nombre_usuario" => $usuario->nombre_usuario,
            "correo" => $usuario->correo,
            "rol" => $usuario->rol->nombre,
            "estado" => $usuario->estado,
            "debe_cambiar_password" => $usuario->debe_cambiar_password,
        ];
    }

    // CU04 paso 7: si la base rechaza un dato único (23505), se muestra un mensaje comprensible.
    // Cualquier otro error sigue su curso (página de error sin detalles).
    private function errorUnicidad(QueryException $e)
    {
        if (($e->errorInfo[0] ?? null) === "23505") {
            $mensajes = [
                "ux_usuarios_nombre_usuario" => ["nombre_usuario", "Ese nombre de usuario ya está registrado"],
                "ux_usuarios_correo" => ["correo_acceso", "Ese correo ya está registrado"],
                "usuarios_persona_id_unique" => ["numero_documento", "Esa persona ya tiene una cuenta de acceso"],
                "ux_personas_documento" => ["numero_documento", "Ya existe una persona con ese documento"],
            ];
            foreach ($mensajes as $indice => [$campo, $mensaje]) {
                if (str_contains($e->getMessage(), $indice)) {
                    return back()->withErrors([$campo => $mensaje])->withInput();
                }
            }
        }

        throw $e;
    }

    // CU04 paso 4: documento en mayúsculas sin espacios, puntos ni guiones.
    // Sin número no se guarda tipo, complemento ni lugar de expedición.
    private function normalizarEntrada(Request $request): void
    {
        foreach (["numero_documento", "complemento"] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => Persona::normalizarDocumento($request->input($campo))]);
            }
        }
        if (empty($request->input("numero_documento"))) {
            $request->merge(["tipo_documento" => null, "complemento" => null, "expedido" => null]);
        }
    }

    // Edad de mayoría (configuraciones_sistema).
    private function edadMayoria(): int
    {
        return (int) ConfiguracionSistema::find("edad_mayoria")->valor;
    }

    // Escapa los comodines de LIKE que escriba el usuario.
    private function escaparLike(string $texto): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $texto);
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:usuarios,id"]];
    }

    // CU04 paso 5: solo roles ACTIVOS (al modificar, también se acepta el rol que ya tiene).
    private function reglaRol(?int $rolActual = null): array
    {
        return ["rol_id" => [
            "bail", "required", "integer",
            function (string $atributo, mixed $valor, \Closure $fallar) use ($rolActual) {
                $valido = Rol::where("id", (int) $valor)->where(fn ($consulta) => $consulta->where("estado", "ACTIVO")->orWhere("id", $rolActual))->exists();
                if (! $valido) {
                    $fallar("Elija un rol activo.");
                }
            },
        ]];
    }

    // CU04 paso 4: para el personal el documento es obligatorio; si hay número debe haber tipo.
    private function reglasDocumento(bool $obligatorio): array
    {
        return [
            "tipo_documento" => [$obligatorio ? "required" : "nullable", "required_with:numero_documento", "in:" . implode(",", array_keys(Persona::TIPOS_DOCUMENTO))],
            "numero_documento" => [$obligatorio ? "required" : "nullable", "string", "max:30"],
            "complemento" => ["nullable", "string", "max:10"],
            "expedido" => ["nullable", "in:" . implode(",", array_keys(Persona::EXPEDIDOS))],
        ];
    }

    // CU04 pasos 4 a 7. $persona: la persona existente (null = nueva, se validan sus datos).
    // $mayorDeEdad: la persona nueva va a ser personal. Al modificar, $idIgnorar es la propia cuenta.
    private function reglas(Rol $rol, ?Persona $persona, bool $mayorDeEdad, ?int $idIgnorar = null): array
    {
        $reglas = [];
        $mayoria = $this->edadMayoria();

        // CU04 paso 4: datos de la persona nueva.
        if ($persona === null) {
            $reglas = [
                "nombres" => ["required", "string", "max:255"],
                "apellido_paterno" => ["required", "string", "max:255"],
                "apellido_materno" => ["nullable", "string", "max:255"],
                "fecha_nacimiento" => [
                    "bail", "required", "date", "before_or_equal:today",
                    function (string $atributo, mixed $valor, \Closure $fallar) use ($mayorDeEdad, $mayoria) {
                        if ($mayorDeEdad && Carbon::parse($valor)->age < $mayoria) {
                            $fallar("El personal de la clínica debe ser mayor de edad ({$mayoria} años o más).");
                        }
                    },
                ],
                "sexo" => ["required", "in:M,F"],
                "telefono" => ["required", "string", "regex:/^[0-9+\-\s]{7,20}$/"],
                "correo" => ["nullable", "email", "max:255"],
                "direccion" => ["nullable", "string", "max:255"],
            ];
        }

        // CU04 paso 5: datos del perfil que se crea con el rol.
        if ($rol->nombre === "RECEPCIONISTA" && ! $persona?->recepcionista) {
            $reglas["turno"] = ["required", "in:" . implode(",", array_keys(self::TURNOS))];
            $reglas["fecha_ingreso"] = ["required", "date", "before_or_equal:today"];
        }
        if ($rol->nombre === "ADMINISTRADOR" && ! $persona?->administrador) {
            $reglas["cargo"] = ["required", "string", "max:255"];
        }

        // CU04 pasos 6 y 7: cuenta de acceso con nombre de usuario y correo únicos (sin distinguir mayúsculas).
        return $reglas + [
            "nombre_usuario" => [
                "bail", "required", "string", "regex:/^[A-Za-z0-9._-]{3,50}$/",
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    if ($this->existeEnUsuarios("nombre_usuario", $valor, $idIgnorar)) {
                        $fallar("Ese nombre de usuario ya está registrado");
                    }
                },
            ],
            "correo_acceso" => [
                "bail", "required", "email", "max:255",
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    if ($this->existeEnUsuarios("correo", $valor, $idIgnorar)) {
                        $fallar("Ese correo ya está registrado");
                    }
                },
            ],
        ];
    }

    // CU04 paso 7: lower(columna) = lower(?), sin contar la propia cuenta. $columna es fija en el código.
    private function existeEnUsuarios(string $columna, string $valor, ?int $idIgnorar): bool
    {
        return Usuario::whereRaw("lower({$columna}) = lower(?)", [$valor])
            ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
            ->exists();
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el usuario.",
            "id.integer" => "El usuario indicado no es válido.",
            "id.exists" => "El usuario indicado no existe.",
            "rol_id.required" => "Elija el rol de la cuenta.",
            "rol_id.integer" => "El rol elegido no es válido.",
            "tipo_documento.required" => "El campo Tipo de documento es obligatorio.",
            "tipo_documento.required_with" => "Indique el tipo de documento.",
            "tipo_documento.in" => "El tipo de documento no es válido.",
            "numero_documento.required" => "El campo Número de documento es obligatorio.",
            "numero_documento.max" => "El número de documento no puede superar los 30 caracteres.",
            "complemento.max" => "El complemento no puede superar los 10 caracteres.",
            "expedido.in" => "El lugar de expedición no es válido.",
            "nombres.required" => "El campo Nombres es obligatorio.",
            "nombres.max" => "Los nombres no pueden superar los 255 caracteres.",
            "apellido_paterno.required" => "El campo Apellido paterno es obligatorio.",
            "apellido_paterno.max" => "El apellido paterno no puede superar los 255 caracteres.",
            "apellido_materno.max" => "El apellido materno no puede superar los 255 caracteres.",
            "fecha_nacimiento.required" => "El campo Fecha de nacimiento es obligatorio.",
            "fecha_nacimiento.date" => "La fecha de nacimiento no es válida.",
            "fecha_nacimiento.before_or_equal" => "La fecha de nacimiento no puede ser futura.",
            "sexo.required" => "El campo Sexo es obligatorio.",
            "sexo.in" => "El sexo debe ser M o F.",
            "telefono.required" => "El campo Teléfono es obligatorio.",
            "telefono.regex" => "El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).",
            "correo.email" => "El correo no tiene un formato válido.",
            "correo.max" => "El correo no puede superar los 255 caracteres.",
            "direccion.max" => "La dirección no puede superar los 255 caracteres.",
            "turno.required" => "Elija el turno de la recepcionista.",
            "turno.in" => "El turno debe ser Mañana, Tarde o Completo.",
            "fecha_ingreso.required" => "El campo Fecha de ingreso es obligatorio.",
            "fecha_ingreso.date" => "La fecha de ingreso no es válida.",
            "fecha_ingreso.before_or_equal" => "La fecha de ingreso no puede ser futura.",
            "cargo.required" => "El campo Cargo es obligatorio.",
            "cargo.max" => "El cargo no puede superar los 255 caracteres.",
            "nombre_usuario.required" => "El campo Nombre de usuario es obligatorio.",
            "nombre_usuario.regex" => "El nombre de usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.",
            "correo_acceso.required" => "El campo Correo de acceso es obligatorio.",
            "correo_acceso.email" => "El correo de acceso no tiene un formato válido.",
            "correo_acceso.max" => "El correo de acceso no puede superar los 255 caracteres.",
        ];
    }
}
