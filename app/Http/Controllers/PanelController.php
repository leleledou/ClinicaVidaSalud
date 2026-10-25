<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * CU01 paso 8: panel de inicio después de iniciar sesión.
 */
class PanelController extends Controller
{
    // Saludo con los nombres de la persona y su rol.
    public function index(Request $request)
    {
        $usuario = $request->user()->load(["persona", "rol"]);

        return view("panel", compact("usuario"));
    }
}
