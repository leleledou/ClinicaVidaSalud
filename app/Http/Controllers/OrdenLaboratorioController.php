<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\Consulta;
use App\Models\OrdenLaboratorio;
use App\Models\ResultadoLaboratorio;
use App\Services\LaboratorioService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * CU34 Solicitar orden de laboratorio (RF-35). Solo el médico de la consulta (perfil ACTIVO) con
 * consultas.gestionar, desde la consulta EN_CURSO (consultas/show). No hay borrado físico (RN11,
 * trigger sin DELETE): una orden SOLICITADA se anula. Las reglas de negocio están en LaboratorioService.
 *
 * Lista y ficha de órdenes: CU35 Registrar resultados de laboratorio (RF-36; laboratorio.cargar_resultados)
 * y CU36 Revisar resultados y evolución (RF-37; laboratorio.consultar_resultados o resultados.propios).
 * Qué órdenes ve cada uno lo decide OrdenLaboratorio::alcance / visiblesPara; fuera de su alcance, 403.
 */
class OrdenLaboratorioController extends Controller
{
    // Campos de la orden que llegan del formulario.
    private const CAMPOS = ["tipo_examen_id", "prioridad"];

    // CU35 paso 1 / CU36 paso 1: filtros de estado de la lista («PENDIENTES» = SOLICITADA y MUESTRA_TOMADA).
    private const FILTROS = ["PENDIENTES" => "Pendientes (sin resultados)", "TODOS" => "Todos"] + OrdenLaboratorio::ESTADOS;

    public function __construct(private LaboratorioService $laboratorio)
    {
    }

    /**
     * CU35 paso 1 / CU36 paso 1: lista paginada de las órdenes que el usuario puede ver, con filtro de estado y
     * texto (código de la orden, o nombre o código del paciente, sin tildes con fn_normalizar_texto).
     * Recepcionista (alcance «laboratorio»): por defecto las pendientes, primero URGENTE y luego la más antigua. Médico y paciente:
     * por defecto todas, la más reciente primero. CU36 A1: sin ningún permiso de laboratorio, 403.
     */
    public function index(Request $request)
    {
        $alcance = OrdenLaboratorio::alcance($request->user());
        abort_if($alcance === null, 403);

        $texto = trim((string) $request->query("q", ""));
        $estado = array_key_exists((string) $request->query("estado"), self::FILTROS)
            ? $request->query("estado")
            : ($alcance === "laboratorio" ? "PENDIENTES" : "TODOS");

        $ordenes = OrdenLaboratorio::visiblesPara($request->user())
            ->with(["tipoExamen", "consulta.cita.paciente.persona", "consulta.cita.ficha.horario.medicoEspecialidad.medico.persona"])
            ->when($texto !== "", function ($consulta) use ($texto) {
                $patron = "%" . $this->escaparLike($texto) . "%";
                $consulta->where(fn ($condicion) => $condicion
                    ->whereRaw("fn_normalizar_texto(ordenes_laboratorio.codigo) LIKE fn_normalizar_texto(?)", [$patron])
                    ->orWhereHas("consulta.cita.paciente", fn ($paciente) => $paciente
                        ->whereRaw("fn_normalizar_texto(pacientes.codigo) LIKE fn_normalizar_texto(?)", [$patron])
                        ->orWhereHas("persona", fn ($persona) => $persona
                            ->whereRaw("fn_normalizar_texto(nombres || ' ' || apellido_paterno || ' ' || coalesce(apellido_materno, '')) LIKE fn_normalizar_texto(?)", [$patron]))));
            })
            ->when($estado === "PENDIENTES", fn ($consulta) => $consulta->whereIn("estado", OrdenLaboratorio::PENDIENTES)
                ->orderByRaw("CASE WHEN prioridad = 'URGENTE' THEN 0 ELSE 1 END")
                ->orderBy("fecha_solicitud"))
            ->when($estado !== "PENDIENTES", fn ($consulta) => $consulta
                ->when($estado !== "TODOS", fn ($filtro) => $filtro->where("estado", $estado))
                ->orderByDesc("fecha_solicitud"))
            ->orderBy("id")
            ->paginate(15)
            ->withQueryString();

        return view("laboratorio.ordenes.index", [
            "ordenes" => $ordenes,
            "texto" => $texto,
            "estado" => $estado,
            "filtros" => self::FILTROS,
            "prioridades" => OrdenLaboratorio::PRIORIDADES,
            // «Mis resultados» para el paciente.
            "titulo" => $alcance === "paciente" ? "Mis resultados" : "Órdenes de laboratorio",
        ]);
    }

    /**
     * CU35 paso 2 / CU36 pasos 2 a 4: ficha de la orden. Datos del paciente y del examen; formulario de
     * resultados (CU35) si está pendiente y el usuario tiene laboratorio.cargar_resultados; tabla de
     * resultados; revisión del médico solicitante y evolución de cada parámetro NUMERICO (CU36).
     */
    public function show(Request $request, $id)
    {
        $orden = OrdenLaboratorio::findOrFail($id);
        // CU36 A2: una orden fuera de su alcance, 403.
        $alAlcance = $orden->alAlcanceDe($request->user());
        abort_unless($alAlcance, 403);

        // CU36 paso 3: el médico solicitante, al abrir una orden RESULTADO_LISTO, la marca REVISADA (A4: nadie más).
        $this->laboratorio->marcarRevisada($orden, $request->user());

        $orden->load([
            "tipoExamen.parametros",
            "consulta.cita.paciente.persona",
            "consulta.cita.ficha.horario.medicoEspecialidad.medico.persona",
            "resultados" => fn ($resultado) => $resultado->join("parametros_laboratorio", "parametros_laboratorio.id", "=", "resultados_laboratorio.parametro_laboratorio_id")
                ->orderBy("parametros_laboratorio.orden")
                ->select("resultados_laboratorio.*")
                ->with(["parametro", "rango", "usuarioCarga.persona"]),
        ]);
        $paciente = $orden->consulta->cita->paciente;
        $usuario = $request->user();

        // CU36 paso 4: evolución de cada parámetro NUMERICO del examen con todos los resultados del paciente.
        $verEvolucion = $usuario->tienePermiso("laboratorio.consultar_resultados") || $usuario->tienePermiso("resultados.propios");
        $evoluciones = $verEvolucion
            ? $orden->tipoExamen->parametros->where("tipo_valor", "NUMERICO")
                ->map(fn ($parametro) => ["parametro" => $parametro, "resultados" => ResultadoLaboratorio::evolucion($paciente->id, $parametro->id)])
                ->filter(fn ($evolucion) => $evolucion["resultados"]->isNotEmpty())
                ->values()
            : collect();

        return view("laboratorio.ordenes.show", [
            "orden" => $orden,
            "paciente" => $paciente,
            "medico" => $orden->medicoSolicitante(),
            "prioridades" => OrdenLaboratorio::PRIORIDADES,
            // CU35 paso 2 / A1: solo pendiente y solo la Recepcionista (alcance «laboratorio»); el médico solo revisa.
            "puedeCargar" => in_array($orden->estado, OrdenLaboratorio::PENDIENTES, true) && OrdenLaboratorio::alcance($usuario) === "laboratorio",
            "evoluciones" => $evoluciones,
        ]);
    }

    // CU34 pasos 1 y 2: solicitar un examen en la consulta. La consulta viaja oculta.
    public function store(Request $request)
    {
        $request->validate(["consulta_id" => ["required", "integer", "exists:consultas,id"]], $this->mensajes());
        $consulta = Consulta::findOrFail($request->input("consulta_id"));
        $datos = $request->validate($this->reglas(), $this->mensajes());

        try {
            $orden = $this->laboratorio->solicitar($consulta, Arr::only($datos, self::CAMPOS), $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["orden" => $e->getMessage()])->withInput();
        }

        return redirect()->route("consultas.mostrar", $consulta->id)->with("exito", "Orden {$orden->codigo} solicitada");
    }

    // CU34 paso 3: delete = anular (RN11: no se borra). Una orden SOLICITADA, solo el médico que la pidió. El id viaja oculto.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $orden = OrdenLaboratorio::findOrFail($request->input("id"));

        try {
            $orden = $this->laboratorio->anular($orden, $request->user());
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["operacion" => $e->getMessage()]);
        }

        return back()->with("exito", "Orden {$orden->codigo} anulada");
    }

    // Texto de búsqueda sin comodines de LIKE.
    private function escaparLike(string $texto): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $texto);
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:ordenes_laboratorio,id"]];
    }

    // CU34 paso 1: examen y prioridad (ck_ordenes_prioridad). Que el examen esté ACTIVO y con parámetros lo revisa LaboratorioService.
    private function reglas(): array
    {
        return [
            "tipo_examen_id" => ["required", "integer", "exists:tipos_examen,id"],
            "prioridad" => ["required", Rule::in(array_keys(OrdenLaboratorio::PRIORIDADES))],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la orden.",
            "id.integer" => "La orden indicada no es válida.",
            "id.exists" => "La orden indicada no existe.",
            "consulta_id.required" => "No se indicó la consulta.",
            "consulta_id.integer" => "La consulta indicada no es válida.",
            "consulta_id.exists" => "La consulta indicada no existe.",
            "tipo_examen_id.required" => "Elija el examen.",
            "tipo_examen_id.integer" => "El examen elegido no es válido.",
            "tipo_examen_id.exists" => "El examen elegido no existe.",
            "prioridad.required" => "Elija la prioridad.",
            "prioridad.in" => "La prioridad no es válida.",
        ];
    }
}
