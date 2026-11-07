<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaNegocioException;
use App\Models\Bitacora;
use App\Models\BloqueoAgenda;
use App\Models\Cita;
use App\Models\Consultorio;
use App\Models\Ficha;
use App\Models\Medico;
use App\Services\AgendaService;
use App\Services\CitaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU25 Gestionar bloqueos de agenda (RF-12). Requiere el permiso bloqueos.gestionar.
 * Un bloqueo (feriado de la CLINICA, cierre de un CONSULTORIO o ausencia de un MEDICO) bloquea las
 * fichas que cruza (AgendaService) y cancela sus citas vigentes avisando a los pacientes
 * (CitaService::cancelarPorBloqueo). RN11: el bloqueo terminado o en curso se conserva como
 * historial; solo uno que todavía no empezó se elimina al levantarlo (bloqueos_agenda no está entre
 * las tablas sin DELETE). Levantarlo vuelve a habilitar las fichas, pero no restaura las citas.
 */
class BloqueoAgendaController extends Controller
{
    // Campos que se guardan y que se registran en la bitácora.
    private const CAMPOS = ["alcance", "medico_id", "consultorio_id", "inicio", "fin", "motivo"];

    private const SITUACIONES = ["proximos" => "Próximos", "en_curso" => "En curso", "terminados" => "Terminados"];

    public function __construct(private CitaService $citas, private AgendaService $agenda)
    {
    }

    // CU25 pasos 1-2: lista paginada con filtros por alcance y situación, y el formulario de registro.
    public function index(Request $request)
    {
        $alcance = in_array($request->query("alcance"), BloqueoAgenda::ALCANCES, true) ? $request->query("alcance") : null;
        $situacion = array_key_exists((string) $request->query("situacion"), self::SITUACIONES) ? $request->query("situacion") : null;
        $ahora = now();

        $bloqueos = BloqueoAgenda::with(["medico.persona", "consultorio"])
            ->select("bloqueos_agenda.*")
            // CU25 paso 2: fichas bloqueadas que cruza cada bloqueo.
            ->selectRaw(AgendaService::SQL_FICHAS_BLOQUEADAS . " as fichas_bloqueadas")
            ->when($alcance, fn ($consulta) => $consulta->where("alcance", $alcance))
            ->when($situacion === "proximos", fn ($consulta) => $consulta->where("inicio", ">", $ahora))
            ->when($situacion === "en_curso", fn ($consulta) => $consulta->where("inicio", "<=", $ahora)->where("fin", ">", $ahora))
            ->when($situacion === "terminados", fn ($consulta) => $consulta->where("fin", "<=", $ahora))
            ->orderByDesc("inicio")
            ->orderByDesc("id")
            ->paginate(15)
            ->withQueryString();

        // CU25 paso 3: solo médicos y consultorios ACTIVOS.
        $medicos = Medico::join("personas", "personas.id", "=", "medicos.persona_id")
            ->where("medicos.estado", "ACTIVO")
            ->orderBy("personas.apellido_paterno")
            ->orderBy("personas.nombres")
            ->get(["medicos.id", "medicos.codigo", "personas.nombres", "personas.apellido_paterno", "personas.apellido_materno"]);
        $consultorios = Consultorio::where("estado", "ACTIVO")->orderBy("nombre")->get(["id", "nombre", "ubicacion"]);

        return view("bloqueos.index", [
            "bloqueos" => $bloqueos,
            "alcance" => $alcance,
            "situacion" => $situacion,
            "alcances" => BloqueoAgenda::ALCANCES,
            "situaciones" => self::SITUACIONES,
            "medicos" => $medicos,
            "consultorios" => $consultorios,
            "ahora" => $ahora,
        ]);
    }

    // CU25 pasos 3 a 7: registrar un bloqueo en dos pasos. Sin confirmar=1 no guarda nada: vuelve al
    // formulario con el resumen (paso 6). Con confirmar=1 lo registra (paso 7).
    public function store(Request $request)
    {
        // CU25 paso 4: ck_bloqueos_alcance_destino (el destino que no corresponde al alcance se descarta).
        $request->merge([
            "medico_id" => $request->input("alcance") === "MEDICO" ? $request->input("medico_id") : null,
            "consultorio_id" => $request->input("alcance") === "CONSULTORIO" ? $request->input("consultorio_id") : null,
        ]);
        $this->limpiarMotivo($request);

        // CU25 pasos 4-5: validar.
        $datos = $request->validate($this->reglas(), $this->mensajes());
        $datos["medico_id"] ??= null;
        $datos["consultorio_id"] ??= null;
        $inicio = Carbon::createFromFormat("Y-m-d\TH:i", $datos["inicio"])->startOfMinute();
        $fin = Carbon::createFromFormat("Y-m-d\TH:i", $datos["fin"])->startOfMinute();
        $ahora = now();

        // CU25 paso 6: primer envío, solo el resumen de las fichas que se bloquearán y las citas que se cancelarán.
        if (! $request->boolean("confirmar")) {
            $ids = $this->agenda->fichasAfectadas($datos["alcance"], $datos["medico_id"], $datos["consultorio_id"], $inicio, $fin, $ahora)->pluck("fichas.id");

            return back()->withInput()->with("resumen", [
                "fichas" => Ficha::whereIn("id", $ids)->where("estado", "HABILITADA")->count(),
                "citas" => $this->citas->citasVigentesDeFichas($ids)->map(fn (Cita $cita) => [
                    "codigo" => $cita->codigo,
                    "paciente" => $cita->paciente->persona->nombre_completo,
                    "medico" => $cita->ficha->horario->medicoEspecialidad->medico->persona->nombre_completo,
                    "fecha" => Carbon::parse($cita->ficha->fecha)->format("d/m/Y"),
                    "hora" => substr($cita->ficha->hora_inicio, 0, 5) . "–" . substr($cita->ficha->hora_fin, 0, 5),
                ])->all(),
            ]);
        }

        // CU25 paso 7: todo en UNA transacción. Los triggers no imponen orden entre citas y fichas
        // (fichas no tiene triggers; el de citas solo valida cita_reemplazada_id): se cancelan las
        // citas y luego se bloquean las fichas.
        try {
            [$bloqueo, $bloqueadas, $canceladas] = DB::transaction(function () use ($datos, $inicio, $fin, $ahora, $request) {
                $bloqueo = BloqueoAgenda::create(array_merge($datos, ["inicio" => $inicio, "fin" => $fin]));

                // RN13: las fichas afectadas quedan bloqueadas (FOR UPDATE) hasta el final: una reserva
                // simultánea espera y luego las ve BLOQUEADAS (CitaService::validarFicha).
                $ids = $this->agenda->fichasAfectadas($bloqueo->alcance, $bloqueo->medico_id, $bloqueo->consultorio_id, $inicio, $fin, $ahora)
                    ->lock("for update of fichas")
                    ->pluck("fichas.id");

                // CU25 paso 7: cancelar las citas PROGRAMADAS o CONFIRMADAS de esas fichas (avisa al paciente y al médico).
                $canceladas = 0;
                foreach ($this->citas->citasVigentesDeFichas($ids) as $cita) {
                    $this->citas->cancelarPorBloqueo($cita, $bloqueo, $request->user());
                    $canceladas++;
                }

                // CU25 paso 7: las fichas HABILITADAS pasan a BLOQUEADA.
                $bloqueadas = Ficha::whereIn("id", $ids)->where("estado", "HABILITADA")->update(["estado" => "BLOQUEADA"]);

                Bitacora::registrar("CREAR_BLOQUEO", "agenda", $request->user()->id, true, "bloqueos_agenda", $bloqueo->id, "WEB", null,
                    array_merge($this->foto($bloqueo), ["fichas_bloqueadas" => $bloqueadas, "citas_canceladas" => $canceladas]));

                return [$bloqueo, $bloqueadas, $canceladas];
            });
        } catch (ReglaNegocioException $e) {
            return back()->withErrors(["operacion" => $e->getMessage()])->withInput();
        }

        // CU25 paso 8: a la ficha del bloqueo.
        return redirect()->route("bloqueos.mostrar", $bloqueo->id)
            ->with("exito", "Bloqueo registrado. Se bloquearon {$bloqueadas} fichas y se cancelaron {$canceladas} citas (se avisó a los pacientes y a los médicos).");
    }

    // CU25 paso 8: datos del bloqueo, fichas afectadas y citas que canceló.
    public function show($id)
    {
        $bloqueo = BloqueoAgenda::with(["medico.persona", "consultorio"])->findOrFail($id);

        // Todas las fichas de su alcance que cruza (pasadas y futuras), con su médico y especialidad.
        $fichas = $this->agenda->fichasDelAlcance($bloqueo->alcance, $bloqueo->medico_id, $bloqueo->consultorio_id, $bloqueo->inicio, $bloqueo->fin)
            ->join("especialidades", "especialidades.id", "=", "me.especialidad_id")
            ->join("medicos", "medicos.id", "=", "me.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->leftJoin("consultorios", "consultorios.id", "=", "h.consultorio_id")
            ->select("fichas.*", "especialidades.nombre as especialidad", "personas.nombres", "personas.apellido_paterno",
                "personas.apellido_materno", "consultorios.nombre as consultorio")
            ->orderBy("fichas.fecha")
            ->orderBy("fichas.hora_inicio")
            ->orderBy("personas.apellido_paterno")
            ->paginate(50)
            ->withQueryString();

        // Citas canceladas por este bloqueo (motivo «Bloqueo de agenda #ID: …», CitaService::motivoBloqueo).
        $canceladas = Cita::with(["paciente.persona", "ficha.horario.medicoEspecialidad.medico.persona", "ficha.horario.medicoEspecialidad.especialidad"])
            ->where("estado", "CANCELADA")
            ->where("motivo_cancelacion", "like", CitaService::prefijoMotivoBloqueo($bloqueo->id) . "%")
            ->orderBy("codigo")
            ->get();

        return view("bloqueos.show", [
            "bloqueo" => $bloqueo,
            "fichas" => $fichas,
            "canceladas" => $canceladas,
            "situacion" => $this->situacion($bloqueo),
        ]);
    }

    // CU25 A1: solo se modifica el motivo (las fechas y el alcance no). El id viaja oculto.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $bloqueo = BloqueoAgenda::findOrFail($request->input("id"));

        $this->limpiarMotivo($request);
        $datos = $request->validate(["motivo" => $this->reglas()["motivo"]], $this->mensajes());

        // CU25 A1: sin cambios, no se guarda ni se registra nada.
        if ($datos["motivo"] === $bloqueo->motivo) {
            return back()->with("aviso", "No hay cambios");
        }

        DB::transaction(function () use ($bloqueo, $datos, $request) {
            $anterior = $bloqueo->motivo;
            $bloqueo->update(["motivo" => $datos["motivo"]]);

            Bitacora::registrar("MODIFICAR_BLOQUEO", "agenda", $request->user()->id, true, "bloqueos_agenda", $bloqueo->id, "WEB", ["motivo" => $anterior], ["motivo" => $bloqueo->motivo]);
        });

        return back()->with("exito", "Motivo del bloqueo actualizado");
    }

    // CU25 A2: levantar el bloqueo. El id viaja oculto en el formulario.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $bloqueo = BloqueoAgenda::findOrFail($request->input("id"));
        $ahora = now();

        // CU25 A2 paso 1 (RN11): uno terminado queda como historial.
        if ($bloqueo->fin->lessThanOrEqualTo($ahora)) {
            return back()->withErrors(["operacion" => "El bloqueo ya terminó: queda como historial y no se puede levantar."]);
        }

        // CU25 A2 pasos 2 a 4: todo en UNA transacción.
        $habilitadas = DB::transaction(function () use ($bloqueo, $ahora, $request) {
            $anteriores = $this->foto($bloqueo);
            $finOriginal = $bloqueo->fin->copy();
            $eliminado = $bloqueo->inicio->greaterThanOrEqualTo($ahora);

            // Fichas BLOQUEADAS de su alcance, con inicio futuro, dentro de su rango (FOR UPDATE).
            $fichas = $this->agenda->fichasAfectadas($bloqueo->alcance, $bloqueo->medico_id, $bloqueo->consultorio_id, $bloqueo->inicio, $finOriginal, $ahora)
                ->where("fichas.estado", "BLOQUEADA")
                ->lock("for update of fichas")
                ->get(["fichas.id", "fichas.horario_atencion_id", "fichas.fecha", "fichas.hora_inicio", "fichas.hora_fin"]);

            // CU25 A2 paso 2: si no empezó, se elimina; si está en curso, termina ahora (ck_bloqueos_rango: fin > inicio).
            if ($eliminado) {
                $bloqueo->delete();
            } else {
                $bloqueo->update(["fin" => $ahora]);
            }

            // CU25 A2 paso 3: vuelven a HABILITADA salvo las que cruce otro bloqueo (este ya no las cruza).
            $habilitar = $this->agenda->fichasQueSeHabilitan($fichas, $ahora, $finOriginal);
            $habilitadas = $habilitar ? Ficha::whereIn("id", $habilitar)->update(["estado" => "HABILITADA"]) : 0;

            Bitacora::registrar("LEVANTAR_BLOQUEO", "agenda", $request->user()->id, true, "bloqueos_agenda", $anteriores["id"], "WEB", $anteriores,
                $eliminado
                    ? ["eliminado" => true, "fichas_habilitadas" => $habilitadas]
                    : ["fin" => $ahora->format("Y-m-d H:i:s"), "fichas_habilitadas" => $habilitadas]);

            return $habilitadas;
        });

        // CU25 A2 paso 4: las citas canceladas NO se restauran.
        return redirect()->route("bloqueos.listar")
            ->with("exito", "Bloqueo levantado. Se habilitaron {$habilitadas} fichas.")
            ->with("aviso", "Las citas canceladas por el bloqueo no se restauran: si el paciente lo desea, programe una cita nueva.");
    }

    // Próximo, en curso o terminado.
    private function situacion(BloqueoAgenda $bloqueo): string
    {
        $ahora = now();

        return match (true) {
            $bloqueo->inicio->greaterThan($ahora) => "proximos",
            $bloqueo->fin->greaterThan($ahora) => "en_curso",
            default => "terminados",
        };
    }

    // Datos del bloqueo para la bitácora.
    private function foto(BloqueoAgenda $bloqueo): array
    {
        return ["id" => $bloqueo->id] + array_merge($bloqueo->only(self::CAMPOS), [
            "inicio" => $bloqueo->inicio->format("Y-m-d H:i:s"),
            "fin" => $bloqueo->fin->format("Y-m-d H:i:s"),
        ]);
    }

    // CU25 paso 5 / A1: motivo sin espacios en los extremos.
    private function limpiarMotivo(Request $request): void
    {
        if (is_string($request->input("motivo"))) {
            $request->merge(["motivo" => trim($request->input("motivo"))]);
        }
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:bloqueos_agenda,id"]];
    }

    // CU25 pasos 4-5: alcance y destino (ck_bloqueos_alcance_destino), fechas y motivo. No hay
    // datos únicos, así que no recibe un id a ignorar.
    private function reglas(): array
    {
        return [
            "alcance" => ["required", Rule::in(BloqueoAgenda::ALCANCES)],
            "medico_id" => ["nullable", "required_if:alcance,MEDICO", "integer", Rule::exists("medicos", "id")->where("estado", "ACTIVO")],
            "consultorio_id" => ["nullable", "required_if:alcance,CONSULTORIO", "integer", Rule::exists("consultorios", "id")->where("estado", "ACTIVO")],
            "inicio" => [
                "bail", "required", "date_format:Y-m-d\TH:i",
                // CU25 paso 5: el inicio no puede estar en el pasado (se compara al minuto, como el formulario).
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    if (Carbon::createFromFormat("Y-m-d\TH:i", $valor)->lessThan(now()->startOfMinute())) {
                        $fallar("El inicio no puede estar en el pasado.");
                    }
                },
            ],
            "fin" => [
                "bail", "required", "date_format:Y-m-d\TH:i",
                // CU25 paso 5 / ck_bloqueos_rango: el fin es posterior al inicio.
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    $inicio = request()->input("inicio");
                    if (is_string($inicio) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $inicio) && $valor <= $inicio) {
                        $fallar("El fin debe ser posterior al inicio.");
                    }
                },
            ],
            "motivo" => ["required", "string", "max:1000"],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el bloqueo.",
            "id.integer" => "El bloqueo indicado no es válido.",
            "id.exists" => "El bloqueo indicado no existe.",
            "alcance.required" => "Elija el alcance del bloqueo.",
            "alcance.in" => "El alcance debe ser Toda la clínica, Consultorio o Médico.",
            "medico_id.required_if" => "Elija el médico.",
            "medico_id.integer" => "El médico elegido no es válido.",
            "medico_id.exists" => "El médico elegido no existe o está inactivo.",
            "consultorio_id.required_if" => "Elija el consultorio.",
            "consultorio_id.integer" => "El consultorio elegido no es válido.",
            "consultorio_id.exists" => "El consultorio elegido no existe o está inactivo.",
            "inicio.required" => "El campo Inicio es obligatorio.",
            "inicio.date_format" => "El inicio no es una fecha y hora válida.",
            "fin.required" => "El campo Fin es obligatorio.",
            "fin.date_format" => "El fin no es una fecha y hora válida.",
            "motivo.required" => "El campo Motivo es obligatorio.",
            "motivo.string" => "El motivo debe ser un texto.",
            "motivo.max" => "El motivo no puede superar los 1000 caracteres.",
        ];
    }
}
