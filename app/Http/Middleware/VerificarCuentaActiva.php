<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: cuenta.activa
 * CU01 paso 5 durante la sesión: si la cuenta pasó a INACTIVO con la sesión
 * abierta, se cierra la sesión.
 */
class VerificarCuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        // El usuario se lee de la base en cada petición: un cambio de estado se aplica de inmediato.
        if ($request->user() && $request->user()->estado !== "ACTIVO") {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route("login")
                ->with("aviso", "La cuenta está deshabilitada. Comuníquese con el administrador.");
        }

        return $next($request);
    }
}
