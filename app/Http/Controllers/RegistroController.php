<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ConfiguracionSistema;
use App\Models\Notificacion;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Rol;
use App\Models\Usuario;
use App\Rules\PoliticaPassword;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * CU12 Autorregistrarse como paciente (RF01, §3.4: registro e inicio de sesión del paciente).
 * Formulario público para una persona mayor de edad sin sesión. Crea persona, paciente (sin
 * recepcionista: autorregistro), historia clínica y cuenta PACIENTE con el correo sin verificar.
 */
class RegistroController extends Controller
{
    private const CAMPOS_PERSONA = ["tipo_documento", "numero_documento", "complemento", "expedido", "nombres", "apellido_paterno",
        "apellido_materno", "fecha_nacimiento", "sexo", "telefono", "correo", "direccion"];

    // CU12 paso 5: el mismo mensaje para todo conflicto de cuenta (no revela datos de terceros).
    private const CUENTA_EN_USO = "El nombre de usuario o el correo ya están en uso.";

    private const ACERCARSE_RECEPCION = "Tus datos ya están registrados en la clínica. Acércate a recepción para activar tu cuenta.";

    // CU12 A2: la respuesta es siempre la misma, exista o no la cuenta.
    private const REENVIO_RESPUESTA = "Si el correo corresponde a una cuenta pendiente de verificación, te enviamos un nuevo enlace.";

    // CU12 pasos 1-2: formulario público de registro.
    public function create()
    {
        return view("auth.registro", ["edadMayoria" => $this->edadMayoria()]);
    }

    // CU12 pasos 3 a 7: crear la cuenta de paciente.
    public function store(Request $request)
    {
        // CU12 paso 3: documento normalizado (mayúsculas, sin espacios, puntos ni guiones).
        $request->merge(Persona::normalizarEntrada($request->only(["tipo_documento", "numero_documento", "complemento", "expedido"])));

        // CU12 paso 5: formato de los datos, mayoría de edad, política de contraseña y privacidad.
        $datos = $request->validate($this->reglas(), $this->mensajes());

        // CU12 paso 4: buscar a la persona por su documento.
        $persona = Persona::buscarPorDocumento($datos["tipo_documento"], $datos["numero_documento"], $datos["complemento"] ?? null);

        // CU12 A1: la persona ya está registrada (por ejemplo, por recepción).
        if ($persona !== null && ($error = $this->errorPersonaExistente($persona, $datos["correo"]))) {
            return back()->withErrors(["registro" => $error])->withInput();
        }

        // CU12 paso 5: nombre de usuario y correo únicos sin distinguir mayúsculas (mensaje genérico).
        if (Usuario::yaUsado("nombre_usuario", $datos["nombre_usuario"]) || Usuario::yaUsado("correo", $datos["correo"])) {
            return back()->withErrors(["registro" => self::CUENTA_EN_USO])->withInput();
        }

        // RN25: la cuenta de paciente usa el rol PACIENTE, que debe estar ACTIVO.
        $rol = Rol::where("nombre", "PACIENTE")->where("estado", "ACTIVO")->first();
        if ($rol === null) {
            return back()->withErrors(["registro" => "El registro en línea no está disponible por ahora. Acércate a recepción."])->withInput();
        }

        try {
            // CU12 paso 6: persona, paciente, historia clínica y cuenta en UNA transacción.
            $usuario = DB::transaction(function () use ($persona, $datos, $rol) {
                // A1: si la persona ya existe, no se modifican sus datos personales.
                $persona ??= Persona::create(Arr::only($datos, self::CAMPOS_PERSONA));

                // Perfil de paciente con código PAC- e historia clínica (lógica compartida con CU10);
                // recepcionista null = autorregistro. A1: si ya es paciente, se usa su perfil.
                $paciente = $persona->paciente ?? Paciente::registrar($persona, null);

                $usuario = Usuario::create([
                    "persona_id" => $persona->id,
                    "rol_id" => $rol->id,
                    "nombre_usuario" => $datos["nombre_usuario"],
                    "correo" => $datos["correo"],
                    "password" => Hash::make($datos["password"]),
                    "estado" => "ACTIVO",
                    "debe_cambiar_password" => false,
                    "correo_verificado_en" => null,
                ]);

                // Bitácora con la cuenta nueva como autora, SIN la contraseña.
                Bitacora::registrar("AUTORREGISTRO", "seguridad", $usuario->id, true, "usuarios", $usuario->id, "WEB", null, [
                    "persona_id" => $persona->id,
                    "paciente" => $paciente->codigo,
                    "nombre_usuario" => $usuario->nombre_usuario,
                    "correo" => $usuario->correo,
                    "rol" => $rol->nombre,
                ]);

                return $usuario;
            });
        } catch (QueryException $e) {
            // CU12 paso 6: otro registro simultáneo tomó el usuario, el correo o el documento.
            if (($e->errorInfo[0] ?? null) === "23505") {
                return back()->withErrors(["registro" => self::CUENTA_EN_USO])->withInput();
            }

            throw $e;
        }

        // CU12 paso 7: correo de verificación y aviso en el login.
        $enlace = $this->enviarVerificacion($usuario);

        return $this->conEnlaceDesarrollo(
            redirect()->route("login")->with("exito", "Cuenta creada. Revisa tu correo para verificarla; mientras tanto puedes iniciar sesión, pero no reservar citas en línea."),
            $enlace
        );
    }

    // CU12 paso 8: verificar el correo con el enlace firmado (funciona con o sin sesión).
    public function verificar(Request $request, $id, $hash)
    {
        $usuario = ctype_digit((string) $id) ? Usuario::find($id) : null;

        // Firma válida y no vencida, y el hash coincide con el correo actual de la cuenta.
        if (! $request->hasValidSignature() || $usuario === null || ! hash_equals(sha1($usuario->correo), (string) $hash)) {
            return view("auth.verificacion", ["valido" => false, "mensaje" => "El enlace no es válido o venció"]);
        }

        if ($usuario->correo_verificado_en !== null) {
            return view("auth.verificacion", ["valido" => true, "mensaje" => "Tu correo ya estaba verificado. Ya puedes reservar citas en línea."]);
        }

        DB::transaction(function () use ($usuario) {
            $usuario->update(["correo_verificado_en" => now()]);

            Bitacora::registrar("VERIFICAR_CORREO", "seguridad", $usuario->id, true, "usuarios", $usuario->id, "WEB", ["correo_verificado_en" => null], ["correo_verificado_en" => $usuario->correo_verificado_en->format("Y-m-d H:i:s")]);
        });

        return view("auth.verificacion", ["valido" => true, "mensaje" => "Correo verificado. Ya puedes reservar citas en línea."]);
    }

    // CU12 A2: formulario para pedir un nuevo enlace (con sesión, se propone el correo de la cuenta).
    public function formularioReenviar(Request $request)
    {
        return view("auth.reenviar", ["correo" => $request->user()?->correo]);
    }

    // CU12 A2: reenviar el enlace solo a una cuenta PACIENTE ACTIVA sin verificar. La respuesta es siempre la misma.
    public function reenviar(Request $request)
    {
        $datos = $request->validate(
            ["correo" => ["required", "email", "max:255"]],
            [
                "correo.required" => "El campo Correo es obligatorio.",
                "correo.email" => "El correo no tiene un formato válido.",
                "correo.max" => "El correo no puede superar los 255 caracteres.",
            ]
        );

        $usuario = Usuario::whereRaw("lower(correo) = lower(?)", [$datos["correo"]])
            ->where("estado", "ACTIVO")
            ->whereNull("correo_verificado_en")
            ->whereHas("rol", fn ($consulta) => $consulta->where("nombre", "PACIENTE"))
            ->first();

        $enlace = $usuario ? $this->enviarVerificacion($usuario) : null;

        return $this->conEnlaceDesarrollo(redirect()->route("registro.reenviar")->with("exito", self::REENVIO_RESPUESTA), $enlace);
    }

    // CU12 A1: devuelve el mensaje que impide registrar a una persona ya registrada, o null si se
    // puede crear su cuenta (sin cuenta, mismo correo de contacto, paciente activo y mayor de edad).
    private function errorPersonaExistente(Persona $persona, string $correo): ?string
    {
        if ($persona->usuario()->exists()) {
            return "Ya existe una cuenta para ese documento. Inicia sesión o acércate a recepción.";
        }

        // El correo ingresado debe coincidir con su correo de contacto registrado.
        if (empty($persona->correo) || mb_strtolower(trim($persona->correo)) !== mb_strtolower(trim($correo))) {
            return self::ACERCARSE_RECEPCION;
        }

        // RN25: un perfil de paciente inactivo no puede recibir una cuenta en línea.
        if ($persona->paciente && $persona->paciente->estado !== "ACTIVO") {
            return self::ACERCARSE_RECEPCION;
        }

        // Precondición: mayor de edad según su fecha de nacimiento registrada.
        if ($persona->edad < $this->edadMayoria()) {
            return $this->mensajeMenor();
        }

        return null;
    }

    // CU12 paso 7 / A2: envía el enlace de verificación (vence en 24 horas) y registra la notificación.
    // Devuelve el enlace (solo se muestra en pantalla en modo desarrollo).
    private function enviarVerificacion(Usuario $usuario): string
    {
        $usuario->loadMissing("persona");
        $enlace = URL::temporarySignedRoute("registro.verificar", now()->addHours(24), ["id" => $usuario->id, "hash" => sha1($usuario->correo)]);
        $titulo = "Clínica Vida Salud: verifica tu correo";

        $enviado = true;
        try {
            Mail::raw(
                "Hola {$usuario->persona->nombres}:\n\n"
                . "Para verificar tu correo y poder reservar citas en línea, abre este enlace (vence en 24 horas):\n{$enlace}\n\n"
                . "Si no creaste esta cuenta, ignora este mensaje.",
                fn ($mensaje) => $mensaje->to($usuario->correo)->subject($titulo)
            );
        } catch (\Throwable $e) {
            // Solo se registra que falló, sin datos internos en pantalla.
            Log::warning("No se pudo enviar el correo de verificación del usuario {$usuario->id}: " . get_class($e));
            $enviado = false;
        }

        // El mensaje guardado no incluye el enlace firmado.
        Notificacion::create([
            "persona_id" => $usuario->persona_id,
            "tipo" => "VERIFICAR_CORREO",
            "canal" => "CORREO",
            "titulo" => $titulo,
            "mensaje" => "Se envió a {$usuario->correo} el enlace para verificar el correo (vence en 24 horas).",
            "referencia_tipo" => "usuarios",
            "referencia_id" => $usuario->id,
            "programada_para" => now(),
            "enviada_en" => $enviado ? now() : null,
            "estado" => $enviado ? "ENVIADA" : "FALLIDA",
            "intentos" => $enviado ? 0 : 1,
        ]);

        return $enlace;
    }

    // CU12 paso 7: en desarrollo (MAIL_MAILER=log) el enlace se muestra además en la pantalla siguiente.
    private function conEnlaceDesarrollo($respuesta, ?string $enlace)
    {
        return $enlace !== null && app()->environment("local") ? $respuesta->with("enlace_verificacion", $enlace) : $respuesta;
    }

    private function mensajeMenor(): string
    {
        return "Debes ser mayor de edad. Si eres menor, tu responsable legal debe registrarte en recepción.";
    }

    // Edad de mayoría (configuraciones_sistema).
    private function edadMayoria(): int
    {
        return ConfiguracionSistema::entero("edad_mayoria");
    }

    // CU12 pasos 2 y 5: datos personales (documento y correo obligatorios), cuenta y privacidad.
    private function reglas(): array
    {
        $mayoria = $this->edadMayoria();
        $reglas = Persona::reglas();

        $reglas["tipo_documento"] = ["required", "in:" . implode(",", array_keys(Persona::TIPOS_DOCUMENTO))];
        $reglas["numero_documento"] = ["required", "string", "max:30"];
        $reglas["correo"] = ["required", "email", "max:255"];
        // Precondición: mayor de edad.
        $reglas["fecha_nacimiento"][] = function (string $atributo, mixed $valor, \Closure $fallar) use ($mayoria) {
            if (Carbon::parse($valor)->age < $mayoria) {
                $fallar($this->mensajeMenor());
            }
        };

        return $reglas + [
            // Mismo formato que CU04.
            "nombre_usuario" => ["required", "string", "regex:" . Usuario::FORMATO_NOMBRE_USUARIO],
            // Misma política que CU03 (regla compartida).
            "password" => ["required", "string", new PoliticaPassword("La contraseña"), "confirmed"],
            "privacidad" => ["accepted"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return Persona::mensajes() + [
            "nombre_usuario.required" => "El campo Nombre de usuario es obligatorio.",
            "nombre_usuario.regex" => "El nombre de usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.",
            "password.required" => "El campo Contraseña es obligatorio.",
            "password.string" => "La contraseña debe ser un texto.",
            "password.confirmed" => "La confirmación no coincide con la contraseña.",
            "privacidad.accepted" => "Debes aceptar las políticas de privacidad.",
        ];
    }
}
