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

        // CU12: un paciente con el correo sin verificar no puede reservar citas en línea.
        $correoPendiente = $usuario->rol->nombre === "PACIENTE" && $usuario->correo_verificado_en === null;

        return view("panel", compact("usuario", "correoPendiente"));
    }
}
