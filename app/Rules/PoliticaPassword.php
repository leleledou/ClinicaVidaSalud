<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * CU03 paso 5 / CU12 paso 5: política de contraseña compartida (cambio de contraseña y
 * autorregistro). Al menos 8 caracteres, una mayúscula, una minúscula, un número y un símbolo.
 * Cada requisito incumplido agrega su propio mensaje. Las mismas reglas usa
 * resources/js/password.js en el navegador.
 */
class PoliticaPassword implements ValidationRule
{
    // $etiqueta: cómo se nombra el campo en los mensajes («La nueva contraseña», «La contraseña»).
    public function __construct(private string $etiqueta = "La contraseña")
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Si no es texto, ya lo informa la regla "string".
        if (! is_string($value)) {
            return;
        }
        if (mb_strlen($value) < 8) {
            $fail("{$this->etiqueta} debe tener al menos 8 caracteres.");
        }
        if (! preg_match("/\p{Lu}/u", $value)) {
            $fail("{$this->etiqueta} debe tener al menos una letra mayúscula.");
        }
        if (! preg_match("/\p{Ll}/u", $value)) {
            $fail("{$this->etiqueta} debe tener al menos una letra minúscula.");
        }
        if (! preg_match("/\d/", $value)) {
            $fail("{$this->etiqueta} debe tener al menos un número.");
        }
        if (! preg_match("/[^\p{L}\p{N}\s]/u", $value)) {
            $fail("{$this->etiqueta} debe tener al menos un símbolo.");
        }
    }
}
