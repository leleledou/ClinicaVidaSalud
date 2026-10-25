<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;

/**
 * CU20 Consultar historial de citas (RF12, §3.4). Solo consulta: historiales_cita es de solo
 * inserción y las citas no se eliminan (RN10), así que el historial incluye las pasadas, canceladas
 * y reasignadas. citas.historial_consultar: recepción, administración y el médico (solo pacientes
 * con los que tiene o tuvo cita, CU11 A1); citas.historial_propio: el paciente (A1: el suyo y el de
 * los menores de los que es responsable legal verificado).
 */
class HistorialCitaController extends Controller
{
    // CU20 pasos 1 a 3 y A1: historial de un paciente con filtros, totales por estado y paginación.
    public function index(Request $request)
    {
        // RN12: sin ninguno de los dos permisos, 403.
        abort_unless(Gate::any(["citas.historial_consultar", "citas.historial_propio"]), 403);

        // A1: el paciente elige entre «Mis citas» y las de los menores a su cargo.
        $opciones = Gate::allows("citas.historial_consultar") ? collect() : Paciente::aCargoDe($request->user()->persona);
        $id = $request->query("paciente_id", $opciones->first()?->id);

        // CU20 paso 1: el personal llega desde la ficha del paciente; sin paciente, a buscarlo (CU11).
        if ($id === null && Gate::allows("pacientes.buscar")) {
            return redirect()->route("pacientes.listar")->with("aviso", "Busque al paciente y use «Historial de citas» en su ficha.");
        }
        abort_unless(ctype_digit((string) $id), 404);

        $paciente = Paciente::with("persona")->findOrFail($id);
        $this->autorizar($request, $paciente, $opciones);

        // CU20 paso 3: filtros (GET) validados en el servidor; los inválidos se ignoran y se muestra el error.
        $filtros = [
            "desde" => $request->query("desde") ?: null,
            "hasta" => $request->query("hasta") ?: null,
            "especialidad_id" => $request->query("especialidad_id") ?: null,
            "estado" => $request->query("estado") ?: null,
        ];
        $validador = Validator::make($filtros, $this->reglas(), $this->mensajes());
        // CU20 paso 3: «desde» no posterior a «hasta» (los dos son opcionales).
        $validador->after(function ($validador) use ($filtros) {
            if (! $validador->errors()->hasAny(["desde", "hasta"]) && $filtros["desde"] && $filtros["hasta"] && $filtros["desde"] > $filtros["hasta"]) {
                $validador->errors()->add("hasta", "La fecha desde no puede ser posterior a la fecha hasta.");
            }
        });
        $errores = $validador->fails() ? $validador->errors() : new MessageBag();
        foreach ($errores->keys() as $campo) {
            $filtros[$campo] = null;
        }

        // CU20 paso 3: totales por estado con los filtros de fecha y especialidad.
        $totales = $this->filtrar($this->consulta($paciente->id), $filtros)
            ->selectRaw("citas.estado, count(*) as total")
            ->groupBy("citas.estado")
            ->pluck("total", "estado");

        // CU20 paso 2: TODAS sus citas, de la más reciente a la más antigua, con los indicadores de
        // reprogramación (RN18) y de reemplazo (CU16 A1). Subconsultas sin datos del usuario.
        $citas = $this->filtrar($this->consulta($paciente->id), $filtros)
            ->when($filtros["estado"], fn ($consulta) => $consulta->where("citas.estado", $filtros["estado"]))
            ->select("citas.id", "citas.codigo", "citas.estado", "citas.origen", "citas.fecha_registro", "citas.cita_reemplazada_id",
                "fichas.fecha", "fichas.hora_inicio", "fichas.hora_fin", "especialidades.nombre as especialidad",
                "pm.nombres as medico_nombres", "pm.apellido_paterno as medico_apellido", "pm.sexo as medico_sexo")
            ->selectRaw("(select count(*) from historiales_cita hc where hc.cita_id = citas.id and hc.ficha_anterior_id is not null and hc.ficha_nueva_id is not null) as reprogramaciones")
            ->selectRaw("(select r.codigo from citas r where r.id = citas.cita_reemplazada_id) as reemplaza_codigo")
            ->selectRaw("(select n.id from citas n where n.cita_reemplazada_id = citas.id) as reemplazada_por_id")
            ->selectRaw("(select n.codigo from citas n where n.cita_reemplazada_id = citas.id) as reemplazada_por_codigo")
            ->orderByDesc("fichas.fecha")
            ->orderByDesc("fichas.hora_inicio")
            ->orderByDesc("citas.id")
            ->paginate(15)
            ->withQueryString();

        return view("historial.paciente", [
            "paciente" => $paciente,
            "opciones" => $opciones,
            "citas" => $citas,
            "totales" => $totales,
            "totalCitas" => Cita::where("paciente_id", $paciente->id)->count(),
            "filtros" => $filtros,
            "errores" => $errores,
            "especialidades" => Especialidad::orderBy("nombre")->get(["id", "nombre"]),
            "estados" => Cita::ESTADOS,
            // A1: el paciente reprograma o cancela desde citas/show (CU16 y CU17).
            "autoservicio" => Gate::allows("citas.autoservicio"),
        ]);
    }

    // CU20 pasos 4 y 5: trazabilidad de una cita (id de la cita): sus datos y la línea de tiempo del historial.
    public function show(Request $request, $id)
    {
        $cita = Cita::with([
            "paciente.persona", "ficha.horario.consultorio", "ficha.horario.medicoEspecialidad.medico.persona",
            "ficha.horario.medicoEspecialidad.especialidad", "usuarioRegistra",
            // CU20 paso 5: en orden cronológico.
            "historiales" => fn ($consulta) => $consulta->orderBy("fecha_hora")->orderBy("id"),
            "historiales.usuario", "historiales.fichaAnterior", "historiales.fichaNueva",
            "citaReemplazada", "reemplazadaPor",
        ])->findOrFail($id);

        $opciones = Gate::allows("citas.historial_consultar") ? collect() : Paciente::aCargoDe($request->user()->persona);
        abort_unless(Gate::any(["citas.historial_consultar", "citas.historial_propio"]), 403);
        $this->autorizar($request, $cita->paciente, $opciones);

        return view("historial.cita", [
            "cita" => $cita,
            // CU20 paso 5: enlace a citas/show (con las acciones) solo si el usuario puede verla.
            "verDetalle" => $cita->puedeVerla($request->user()),
        ]);
    }

    // CU20: quién ve el historial del paciente. citas.historial_consultar (el médico, solo pacientes con
    // los que tiene o tuvo cita, como en CU11 A1, o él mismo) o, con citas.historial_propio, el paciente
    // y los menores a su cargo ($opciones). Si no, 403.
    private function autorizar(Request $request, Paciente $paciente, $opciones): void
    {
        $persona = $request->user()->persona;

        if (Gate::allows("citas.historial_consultar")) {
            $medico = $persona?->medico;
            abort_if($medico && $paciente->persona_id !== $persona->id && ! $paciente->tuvoCitaCon($medico->id), 403);

            return;
        }

        abort_unless($opciones->contains("id", $paciente->id), 403);
    }

    // Citas del paciente con su ficha, médico y especialidad (consulta parametrizada).
    private function consulta(int $pacienteId)
    {
        return Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->join("horarios_atencion", "horarios_atencion.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
            ->join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas as pm", "pm.id", "=", "medicos.persona_id")
            ->where("citas.paciente_id", $pacienteId);
    }

    // CU20 paso 3: rango de fechas (de la ficha) y especialidad.
    private function filtrar($consulta, array $filtros)
    {
        return $consulta
            ->when($filtros["desde"], fn ($c) => $c->where("fichas.fecha", ">=", $filtros["desde"]))
            ->when($filtros["hasta"], fn ($c) => $c->where("fichas.fecha", "<=", $filtros["hasta"]))
            ->when($filtros["especialidad_id"], fn ($c) => $c->where("medicos_especialidades.especialidad_id", (int) $filtros["especialidad_id"]));
    }

    // CU20 paso 3: formato de los filtros.
    private function reglas(): array
    {
        return [
            "desde" => ["nullable", "date_format:Y-m-d"],
            "hasta" => ["nullable", "date_format:Y-m-d"],
            "especialidad_id" => ["nullable", "integer", "exists:especialidades,id"],
            "estado" => ["nullable", "in:" . implode(",", Cita::ESTADOS)],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "desde.date_format" => "La fecha desde no es válida.",
            "hasta.date_format" => "La fecha hasta no es válida.",
            "especialidad_id.integer" => "La especialidad elegida no es válida.",
            "especialidad_id.exists" => "La especialidad elegida no existe.",
            "estado.in" => "El estado elegido no es válido.",
        ];
    }
}
