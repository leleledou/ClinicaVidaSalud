<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\ConfiguracionSistema;
use App\Models\Notificacion;
use App\Services\NotificacionService;
use Illuminate\Http\Request;

/**
 * CU38 Consultar notificaciones (RF-39). Cualquier usuario con sesión, sin permiso especial: cada uno ve y
 * marca solo las de su persona (notificaciones.gestionar del administrador no se usa aquí). También el paso 4
 * de CU37 (aviso del navegador). Las reglas están en NotificacionService.
 */
class NotificacionController extends Controller
{
    // Campos que llegan de la petición: filtro de la bandeja y último aviso que vio el navegador.
    private const CAMPOS = ["filtro", "desde"];

    public function __construct(private NotificacionService $notificaciones)
    {
    }

    /**
     * CU38 pasos 1 y 2: bandeja de la persona del usuario (Notificacion::bandejaDe), las no leídas primero y
     * luego la más reciente; filtro «Todas / No leídas»; paginada. Enlace al detalle solo si puede verlo.
     */
    public function index(Request $request)
    {
        $usuario = $request->user();
        $filtro = array_key_exists((string) $request->query(self::CAMPOS[0]), Notificacion::FILTROS) ? $request->query(self::CAMPOS[0]) : "TODAS";

        $notificaciones = Notificacion::bandejaDe($usuario->persona_id)
            ->when($filtro === "NO_LEIDAS", fn ($consulta) => $consulta->noLeidas())
            ->orderByRaw("CASE WHEN estado = 'LEIDA' THEN 1 ELSE 0 END")
            ->orderByDesc("programada_para")
            ->orderByDesc("id")
            ->paginate(15)
            ->withQueryString();

        return view("notificaciones.index", [
            "notificaciones" => $notificaciones,
            "filtro" => $filtro,
            "filtros" => Notificacion::FILTROS,
            "enlaces" => $this->notificaciones->enlaces($notificaciones, $usuario),
            "noLeidas" => Notificacion::contarNoLeidas($usuario),
            "push" => (bool) $usuario->notificar_por_push,
        ]);
    }

    /**
     * CU37 paso 4: avisos nuevos del propio usuario para el navegador (JSON). ?desde=<último id visto>.
     * La ruta no pasa por el middleware inactividad (si no, la consulta de cada minuto mantendría viva la sesión):
     * aquí solo se lee la marca; si venció (CU02 A1), 401 y la próxima página cierra la sesión.
     */
    public function nuevas(Request $request)
    {
        if ($this->sesionVencida($request)) {
            return response()->json(["mensaje" => "Su sesión expiró"], 401);
        }

        $datos = $request->validate([self::CAMPOS[1] => ["nullable", "integer", "min:0"]], $this->mensajes());

        return response()->json($this->notificaciones->nuevas($request->user(), (int) ($datos[self::CAMPOS[1]] ?? 0)));
    }

    // CU38 paso 3: marcar una como leída (el id viaja oculto). A1: de otra persona, 403.
    public function leer(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $notificacion = Notificacion::findOrFail($request->input("id"));

        try {
            $this->notificaciones->marcarLeida($notificacion, $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["operacion" => $e->getMessage()]);
        }

        return back()->with("exito", "Notificación marcada como leída");
    }

    // CU38 paso 3: marcar todas como leídas.
    public function leerTodas(Request $request)
    {
        $marcadas = $this->notificaciones->marcarTodasLeidas($request->user());

        return back()->with($marcadas > 0 ? "exito" : "aviso", $marcadas > 0
            ? "{$marcadas} notificación(es) marcada(s) como leída(s)"
            : "No hay notificaciones sin leer");
    }

    // CU02 A1 (solo lectura): pasó más tiempo que minutos_inactividad_sesion desde la última página.
    private function sesionVencida(Request $request): bool
    {
        $limite = (int) ConfiguracionSistema::find("minutos_inactividad_sesion")->valor * 60;
        $ultima = (int) $request->session()->get("ultima_actividad", now()->timestamp);

        return (now()->timestamp - $ultima) > $limite;
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:notificaciones,id"]];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la notificación.",
            "id.integer" => "La notificación indicada no es válida.",
            "id.exists" => "La notificación indicada no existe.",
            "desde.integer" => "El último aviso visto no es válido.",
            "desde.min" => "El último aviso visto no es válido.",
        ];
    }
}
