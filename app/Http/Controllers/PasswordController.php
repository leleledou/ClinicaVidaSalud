<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * CU03 Cambiar contraseña (RF01).
 */
class PasswordController extends Controller
{
    // CU03 pasos 1-2: mostrar el formulario.
    public function edit()
    {
        return view("cuenta.password");
    }

    // CU03 pasos 3 a 7: validar y guardar la nueva contraseña.
    public function update(Request $request)
    {
        $usuario = $request->user();

        // CU03 pasos 4 y 5: contraseña actual correcta y política de la nueva.
        $request->validate($this->reglas($usuario), $this->mensajes());

        $nueva = $request->input("password");

        DB::transaction(function () use ($usuario, $nueva) {
            // CU03 paso 6: guardar cifrada (Hash::make usa password_hash() con bcrypt)
            // y quitar la obligación de cambiarla.
            $usuario->password = Hash::make($nueva);
            $usuario->debe_cambiar_password = false;
            $usuario->save();

            // CU03 paso 6: cerrar las sesiones abiertas en otros equipos (middleware auth.session).
            Auth::logoutOtherDevices($nueva);

            // CU03 paso 7: registrar en la bitácora, sin la contraseña.
            Bitacora::registrar("CAMBIO_PASSWORD", "seguridad", $usuario->id, true, "usuarios", $usuario->id);
        });

        // CU03 paso 7: confirmar y volver al panel.
        return redirect()->route("panel")->with("exito", "Contraseña actualizada");
    }

    // CU03 pasos 4 y 5: reglas. Cada requisito incumplido agrega su propio mensaje.
    private function reglas(Usuario $usuario): array
    {
        return [
            "password_actual" => [
                "bail", "required", "string",
                // CU03 paso 4: la contraseña actual debe coincidir (Hash::check).
                function (string $atributo, mixed $valor, \Closure $fallar) use ($usuario) {
                    if (! Hash::check($valor, $usuario->password)) {
                        $fallar("La contraseña actual es incorrecta.");
                    }
                },
            ],
            "password" => [
                "required", "string", "min:8",
                // CU03 paso 5: mayúscula, minúscula, número y símbolo.
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    // Si no es texto, ya lo informa la regla "string".
                    if (! is_string($valor)) {
                        return;
                    }
                    if (! preg_match("/\p{Lu}/u", $valor)) {
                        $fallar("La nueva contraseña debe tener al menos una letra mayúscula.");
                    }
                    if (! preg_match("/\p{Ll}/u", $valor)) {
                        $fallar("La nueva contraseña debe tener al menos una letra minúscula.");
                    }
                    if (! preg_match("/\d/", $valor)) {
                        $fallar("La nueva contraseña debe tener al menos un número.");
                    }
                    if (! preg_match("/[^\p{L}\p{N}\s]/u", $valor)) {
                        $fallar("La nueva contraseña debe tener al menos un símbolo.");
                    }
                },
                // CU03 paso 5: distinta de la actual.
                function (string $atributo, mixed $valor, \Closure $fallar) use ($usuario) {
                    if (is_string($valor) && Hash::check($valor, $usuario->password)) {
                        $fallar("La nueva contraseña debe ser distinta de la actual.");
                    }
                },
                // CU03 paso 5: igual a la confirmación.
                "confirmed",
            ],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "password_actual.required" => "El campo Contraseña actual es obligatorio.",
            "password_actual.string" => "La contraseña actual es incorrecta.",
            "password.required" => "El campo Nueva contraseña es obligatorio.",
            "password.string" => "La nueva contraseña debe ser un texto.",
            "password.min" => "La nueva contraseña debe tener al menos 8 caracteres.",
            "password.confirmed" => "La confirmación no coincide con la nueva contraseña."
        ];
    }
}
