<?php

namespace App\Http\Middleware;

use App\Models\Bitacora;
use App\Models\ConfiguracionSistema;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias: inactividad
 * CU02 A1: cierre automático de la sesión por inactividad.
 */
class ControlarInactividad
{
    public function handle(Request $request, Closure $next): Response
    {
        $ahora = now()->timestamp;

        // CU02 A1: el límite se lee de configuraciones_sistema.
        $limiteSegundos = (int) ConfiguracionSistema::find("minutos_inactividad_sesion")->valor * 60;

        // Marca guardada en CU01 paso 7; si falta, se toma la hora actual.
        $ultimaActividad = (int) $request->session()->get("ultima_actividad", $ahora);

        if ($request->user() && ($ahora - $ultimaActividad) > $limiteSegundos) {
            // CU02 A1 (paso 2): registrar ANTES de cerrar, mientras se conoce al usuario.
            Bitacora::registrar("CIERRE_SESION", "seguridad", $request->user()->id, true, null, null, "SISTEMA");

            // CU02 A1 (paso 3): cerrar la sesión igual que en el cierre manual.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // CU02 A1 (paso 4): volver al login con el aviso.
            return redirect()->route("login")->with("aviso", "Su sesión expiró");
        }

        // Hubo actividad dentro del límite: se actualiza la marca.
        $request->session()->put("ultima_actividad", $ahora);

        return $next($request);
    }
}
