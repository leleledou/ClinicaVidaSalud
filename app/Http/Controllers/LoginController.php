<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ConfiguracionSistema;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * CU01 Iniciar sesión y CU02 Cerrar sesión (RF01).
 */
class LoginController extends Controller
{
    // CU01 paso 1-2: mostrar el formulario de inicio de sesión.
    public function mostrar()
    {
        return view("auth.login");
    }

    // CU01 pasos 3 a 8: validar las credenciales e iniciar la sesión.
    public function ingresar(Request $request)
    {
        // CU01 paso 4: ambos campos completos.
        $request->validate($this->reglas(), $this->mensajes());

        // CU01 paso 4: buscar la cuenta por nombre de usuario o correo, sin distinguir
        // mayúsculas (consulta parametrizada).
        $login = $request->input("usuario");
        $usuario = Usuario::whereRaw("lower(nombre_usuario) = lower(?)", [$login])
            ->orWhereRaw("lower(correo) = lower(?)", [$login])
            ->first();

        // Excepción: usuario inexistente (CU01 A1: se registra el intento sin usuario).
        if ($usuario === null) {
            Bitacora::registrar("LOGIN_FALLIDO", "seguridad", null, false);

            return $this->credencialesIncorrectas();
        }

        // CU01 paso 5: la cuenta debe estar ACTIVA.
        if ($usuario->estado !== "ACTIVO") {
            Bitacora::registrar("LOGIN_FALLIDO", "seguridad", $usuario->id, false);

            return back()
                ->withErrors(["usuario" => "La cuenta está deshabilitada. Comuníquese con el administrador."])
                ->onlyInput("usuario");
        }

        // CU01 paso 5 / A2: la cuenta no debe estar bloqueada.
        if ($usuario->bloqueado_hasta !== null) {
            if ($usuario->bloqueado_hasta->isFuture()) {
                Bitacora::registrar("LOGIN_FALLIDO", "seguridad", $usuario->id, false);

                return $this->cuentaBloqueada($usuario);
            }

            // El bloqueo ya pasó: se empieza a contar de nuevo.
            $usuario->bloqueado_hasta = null;
            $usuario->intentos_fallidos = 0;
        }

        // CU01 paso 6: comparar la contraseña (Hash::check usa password_verify()).
        if (! Hash::check($request->input("password"), $usuario->password)) {
            // CU01 A1: sumar el intento fallido y bloquear al llegar al máximo.
            $usuario->intentos_fallidos = $usuario->intentos_fallidos + 1;

            $maxIntentos = (int) ConfiguracionSistema::find("max_intentos_login")->valor;
            if ($usuario->intentos_fallidos >= $maxIntentos) {
                $minutosBloqueo = (int) ConfiguracionSistema::find("minutos_bloqueo_login")->valor;
                $usuario->bloqueado_hasta = now()->addMinutes($minutosBloqueo);
            }

            // Se guarda fuera de cualquier transacción: el intento queda aunque el login falle.
            $usuario->save();

            Bitacora::registrar("LOGIN_FALLIDO", "seguridad", $usuario->id, false, "usuarios", $usuario->id);

            // CU01 A2: si este intento bloqueó la cuenta, se informa hasta qué hora.
            if ($usuario->bloqueado_hasta !== null) {
                return $this->cuentaBloqueada($usuario);
            }

            return $this->credencialesIncorrectas();
        }

        // CU01 paso 7: credenciales correctas.
        $usuario->intentos_fallidos = 0;
        $usuario->ultimo_acceso = now();
        $usuario->save();

        Auth::login($usuario);
        $request->session()->regenerate();
        $request->session()->put("ultima_actividad", now()->timestamp);

        Bitacora::registrar("INICIO_SESION", "seguridad", $usuario->id);

        // CU01 A3: con contraseña temporal, primero CU03.
        if ($usuario->debe_cambiar_password) {
            return redirect()->route("password.editar")
                ->with("aviso", "Debe cambiar su contraseña temporal antes de continuar.");
        }

        // CU01 paso 8: ir al panel.
        return redirect()->route("panel");
    }

    // CU02 pasos 1 a 4: cerrar la sesión.
    public function salir(Request $request)
    {
        // CU02 paso 2: registrar ANTES de cerrar, mientras se conoce al usuario.
        Bitacora::registrar("CIERRE_SESION", "seguridad", $request->user()->id);

        // CU02 paso 3: salir, invalidar la sesión y regenerar el token CSRF.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // CU02 paso 4: volver al login.
        return redirect()->route("login")->with("exito", "Sesión cerrada correctamente");
    }

    // Excepción de CU01: el mismo mensaje si no existe la cuenta o la contraseña es incorrecta.
    private function credencialesIncorrectas()
    {
        return back()
            ->withErrors(["usuario" => "Usuario o contraseña incorrectos"])
            ->onlyInput("usuario");
    }

    // CU01 A2: cuenta bloqueada temporalmente. Si el bloqueo no termina hoy, se muestra la fecha.
    private function cuentaBloqueada(Usuario $usuario)
    {
        $hasta = $usuario->bloqueado_hasta;
        $mensaje = $hasta->isToday()
            ? "La cuenta está bloqueada temporalmente hasta las " . $hasta->format("H:i") . "."
            : "La cuenta está bloqueada temporalmente hasta el " . $hasta->format("d/m/Y") . " a las " . $hasta->format("H:i") . ".";

        return back()
            ->withErrors(["usuario" => $mensaje])
            ->onlyInput("usuario");
    }

    // CU01 paso 4: reglas del formulario.
    private function reglas(): array
    {
        return [
            "usuario" => ["required", "string"],
            "password" => ["required", "string"]
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "usuario.required" => "El campo Usuario o correo es obligatorio.",
            "usuario.string" => "El campo Usuario o correo debe ser un texto.",
            "password.required" => "El campo Contraseña es obligatorio.",
            "password.string" => "El campo Contraseña debe ser un texto."
        ];
    }
}
