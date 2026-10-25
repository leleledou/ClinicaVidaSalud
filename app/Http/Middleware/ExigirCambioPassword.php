<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: cambio.password
 * CU03 A1: quien tiene una contraseña temporal debe cambiarla antes de usar el
 * sistema. No se aplica a /cuenta/password ni a /logout (ver routes/web.php).
 */
class ExigirCambioPassword
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->user()->debe_cambiar_password) {
            return redirect()->route("password.editar")
                ->with("aviso", "Debe cambiar su contraseña temporal antes de continuar.");
        }

        return $next($request);
    }
}
