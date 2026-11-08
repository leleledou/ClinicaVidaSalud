<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Services\VozService;
use Illuminate\Http\Request;

/**
 * CU39 Ejecutar comando de voz (RF-40). Controlador de interfaz (como LoginController): el panel de voz
 * (resources/js/voz.js) envía la transcripción y la confirmación; aquí solo se valida, se llama a VozService
 * y se responde JSON. Los errores de negocio van con la clave «operacion». Permiso voz.usar (rutas).
 */
class VozController extends Controller
{
    // Campos que llegan del panel.
    private const CAMPOS = ["texto", "token"];

    public function __construct(private VozService $voz)
    {
    }

    // CU39 pasos 2 a 7: interpretar la transcripción y devolver la confirmación (no ejecuta nada).
    public function interpretar(Request $request)
    {
        $datos = $request->validate([self::CAMPOS[0] => ["required", "string", "max:" . VozService::MAX_TEXTO]], $this->mensajes());

        try {
            $resultado = $this->voz->interpretar($datos[self::CAMPOS[0]], $request->user());
        } catch (ReglaNegocioException $e) {
            return response()->json(["errors" => ["operacion" => [$e->getMessage()]]], 422);
        }

        return response()->json($resultado);
    }

    // CU39 pasos 8 y 9: ejecutar la interpretación confirmada (token de la sesión, una sola vez).
    public function ejecutar(Request $request)
    {
        $datos = $request->validate([self::CAMPOS[1] => ["required", "string", "size:40"]], $this->mensajes());

        try {
            [$url, $mensaje] = $this->voz->ejecutar($datos[self::CAMPOS[1]], $request->user());
        } catch (ReglaNegocioException $e) {
            return response()->json(["errors" => ["operacion" => [$e->getMessage()]]], 422);
        }

        // CU39 paso 9: la pantalla de destino muestra el mensaje de éxito.
        if ($mensaje !== null) {
            session()->flash("exito", $mensaje);
        }

        return response()->json(["url" => $url]);
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "texto.required" => "Diga o escriba un comando.",
            "texto.string" => "El comando no es válido.",
            "texto.max" => "El comando no puede tener más de " . VozService::MAX_TEXTO . " caracteres.",
            "token.required" => "Falta la confirmación. Repita el comando.",
            "token.string" => "La confirmación no es válida. Repita el comando.",
            "token.size" => "La confirmación no es válida. Repita el comando.",
        ];
    }
}
