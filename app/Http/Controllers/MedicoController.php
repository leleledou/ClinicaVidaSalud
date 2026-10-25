<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\ConfiguracionSistema;
use App\Models\Especialidad;
use App\Models\HorarioAtencion;
use App\Models\Medico;
use App\Models\MedicoEspecialidad;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU07 Gestionar médicos (RF03). Requiere el permiso medicos.gestionar.
 * Un médico inactivo no recibe nuevas citas pero conserva las históricas (RN11: no se borra).
 */
class MedicoController extends Controller
{
    // Datos personales que se guardan en personas (el documento va aparte: no se modifica).
    private const CAMPOS_PERSONA = ["nombres", "apellido_paterno", "apellido_materno", "fecha_nacimiento", "sexo", "telefono", "correo", "direccion"];
    private const CAMPOS_DOCUMENTO = ["tipo_documento", "numero_documento", "complemento", "expedido"];

    // CU07 paso 2 / RF03: lista paginada, con búsqueda por nombre o especialidad.
    public function index(Request $request)
    {
        $texto = trim((string) $request->query("q", ""));
        $especialidadId = ctype_digit((string) $request->query("especialidad_id")) ? (int) $request->query("especialidad_id") : null;

        $medicos = Medico::with([
                "persona",
                "especialidades" => fn ($consulta) => $consulta->wherePivot("estado", "ACTIVO")->orderBy("nombre"),
            ])
            // RF03: el texto se busca en el nombre completo o en el nombre de la especialidad,
            // sin tildes ni mayúsculas en los dos lados (fn_normalizar_texto) y con parámetros.
            ->when($texto !== "", function ($consulta) use ($texto) {
                $patron = "%" . $this->escaparLike($texto) . "%";
                $consulta->where(function ($condicion) use ($patron) {
                    $condicion->whereHas("persona", fn ($persona) => $persona
                            ->whereRaw("fn_normalizar_texto(nombres || ' ' || apellido_paterno || ' ' || coalesce(apellido_materno, '')) LIKE fn_normalizar_texto(?)", [$patron]))
                        ->orWhereHas("especialidades", fn ($especialidad) => $especialidad
                            ->where("medicos_especialidades.estado", "ACTIVO")
                            ->whereRaw("fn_normalizar_texto(especialidades.nombre) LIKE fn_normalizar_texto(?)", [$patron]));
                });
            })
            // RF03: filtro por especialidad (solo asignaciones activas).
            ->when($especialidadId, fn ($consulta) => $consulta->whereHas("especialidades", fn ($especialidad) => $especialidad
                ->where("especialidades.id", $especialidadId)
                ->where("medicos_especialidades.estado", "ACTIVO")))
            ->orderBy("codigo")
            ->paginate(15)
            ->withQueryString();

        // CU07 paso 6: solo las especialidades ACTIVAS se pueden asignar.
        $especialidades = Especialidad::where("estado", "ACTIVO")->orderBy("nombre")->get(["id", "nombre"]);

        return view("medicos.index", [
            "medicos" => $medicos,
            "especialidades" => $especialidades,
            "texto" => $texto,
            "especialidadId" => $especialidadId,
            "edadMayoria" => $this->edadMayoria(),
        ]);
    }

    // CU07 pasos 3 a 8: registrar un médico.
    public function store(Request $request)
    {
        // CU07 paso 4: para un médico el documento es obligatorio; se normaliza antes de validar.
        $this->normalizarEntrada($request);
        $documento = $request->validate($this->reglasDocumento(), $this->mensajes());

        $persona = Persona::buscarPorDocumento($documento["tipo_documento"], $documento["numero_documento"], $documento["complemento"] ?? null);

        // CU07 paso 7: la misma persona no puede ser médico dos veces.
        if ($persona?->medico) {
            return back()->withErrors(["numero_documento" => "Esa persona ya está registrada como médico ({$persona->medico->codigo})"])->withInput();
        }

        // CU07 paso 4: si la persona ya existe, se reutilizan sus datos y no se validan los del formulario.
        if ($persona !== null && $persona->edad < $this->edadMayoria()) {
            return back()->withErrors(["numero_documento" => "El médico debe ser mayor de edad."])->withInput();
        }

        // CU07 pasos 4 a 7: datos personales (solo persona nueva), profesionales y especialidades.
        $datos = $request->validate($this->reglas($persona === null), $this->mensajes());

        try {
            // CU07 paso 7: todo en UNA transacción.
            $medico = DB::transaction(function () use ($persona, $documento, $datos, $request) {
                $persona ??= Persona::create(array_merge($documento, Arr::only($datos, self::CAMPOS_PERSONA)));

                $medico = Medico::create([
                    "persona_id" => $persona->id,
                    "codigo" => $this->siguienteCodigo(),
                    "registro_profesional" => $datos["registro_profesional"],
                    "biografia" => $datos["biografia"] ?? null,
                    "estado" => "ACTIVO",
                ]);

                // CU07 paso 6: sus especialidades, con UNA principal.
                foreach ($this->idsElegidos($datos) as $especialidadId) {
                    MedicoEspecialidad::create([
                        "medico_id" => $medico->id,
                        "especialidad_id" => $especialidadId,
                        "es_principal" => $especialidadId === (int) $datos["principal"],
                        "estado" => "ACTIVO",
                    ]);
                }

                Bitacora::registrar("CREAR_MEDICO", "catalogos", $request->user()->id, true, "medicos", $medico->id, "WEB", null, $this->foto($medico));

                return $medico;
            });
        } catch (QueryException $e) {
            return $this->errorUnicidad($e);
        }

        // CU07 pasos 7 y 8: confirmar y recordar que falta la cuenta de acceso.
        return redirect()->route("medicos.mostrar", $medico->id)
            ->with("exito", "Médico registrado ({$medico->codigo})")
            ->with("aviso", "Para que el médico ingrese al sistema, cree su cuenta de acceso con el rol MÉDICO en Usuarios.");
    }

    // Ficha del médico: datos personales y profesionales, especialidades y horarios.
    public function show($id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $medico = Medico::with(["persona", "especialidades" => fn ($consulta) => $consulta->orderBy("nombre")])->findOrFail($id);

        $horarios = HorarioAtencion::join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
            ->leftJoin("consultorios", "consultorios.id", "=", "horarios_atencion.consultorio_id")
            ->where("medicos_especialidades.medico_id", $medico->id)
            ->select("horarios_atencion.*", "especialidades.nombre as especialidad", "consultorios.nombre as consultorio")
            ->orderBy("horarios_atencion.dia_semana")
            ->orderBy("horarios_atencion.hora_inicio")
            ->get();

        return view("medicos.show", compact("medico", "horarios"));
    }

    // Modificar: datos personales (salvo el documento), profesionales y especialidades. El id viaja oculto.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $medico = Medico::with("persona")->findOrFail($request->input("id"));

        $this->normalizarEntrada($request);
        $datos = $request->validate($this->reglas(true, $medico->id), $this->mensajes());

        $elegidas = $this->idsElegidos($datos);
        $principal = (int) $datos["principal"];
        $actuales = $medico->medicoEspecialidades()->with("especialidad")->get();

        // Una especialidad que se quita no puede tener horarios ACTIVOS vigentes.
        // Las asignaciones a especialidades INACTIVAS no aparecen en el formulario y no se tocan.
        foreach ($actuales as $asignacion) {
            $seQuita = $asignacion->estado === "ACTIVO" && $asignacion->especialidad->estado === "ACTIVO"
                && ! in_array($asignacion->especialidad_id, $elegidas, true);

            if ($seQuita && $this->horariosVigentes($asignacion->id) > 0) {
                return back()->withErrors(["especialidades" => "No se puede quitar {$asignacion->especialidad->nombre}: tiene horarios vigentes"])->withInput();
            }
        }

        $anteriores = $this->foto($medico);

        try {
            DB::transaction(function () use ($medico, $datos, $elegidas, $principal, $actuales, $anteriores, $request) {
                $medico->persona->update(Arr::only($datos, self::CAMPOS_PERSONA));
                $medico->update(["registro_profesional" => $datos["registro_profesional"], "biografia" => $datos["biografia"] ?? null]);

                // Siempre exactamente una principal: primero se quitan todas y luego se marca la elegida.
                MedicoEspecialidad::where("medico_id", $medico->id)->update(["es_principal" => false]);

                // Lo que se quita NO se borra: pasa a INACTIVO.
                foreach ($actuales as $asignacion) {
                    if ($asignacion->especialidad->estado === "ACTIVO" && ! in_array($asignacion->especialidad_id, $elegidas, true)) {
                        $asignacion->update(["estado" => "INACTIVO"]);
                    }
                }

                // Lo que se marca queda ACTIVO (se reactiva si ya existía).
                foreach ($elegidas as $especialidadId) {
                    MedicoEspecialidad::updateOrCreate(
                        ["medico_id" => $medico->id, "especialidad_id" => $especialidadId],
                        ["estado" => "ACTIVO", "es_principal" => $especialidadId === $principal]
                    );
                }

                Bitacora::registrar("MODIFICAR_MEDICO", "catalogos", $request->user()->id, true, "medicos", $medico->id, "WEB", $anteriores, $this->foto($medico));
            });
        } catch (QueryException $e) {
            return $this->errorUnicidad($e);
        }

        return redirect()->route("medicos.listar")->with("exito", "Médico actualizado");
    }

    // CU07 A1: desactivar (RN11: no se borra). El id viaja oculto en el formulario.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $medico = Medico::findOrFail($request->input("id"));

        // CU07 A1: citas futuras PROGRAMADA o CONFIRMADA (citas → fichas → horarios → medicos_especialidades).
        $futuras = Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->join("horarios_atencion", "horarios_atencion.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->where("medicos_especialidades.medico_id", $medico->id)
            ->whereIn("citas.estado", ["PROGRAMADA", "CONFIRMADA"])
            ->whereRaw("(fichas.fecha + fichas.hora_inicio) > ?", [now()->format("Y-m-d H:i:s")])
            ->count();

        if ($futuras > 0) {
            return redirect()->route("medicos.listar")
                ->withErrors(["operacion" => "El médico tiene {$futuras} cita(s) futura(s) programada(s) o confirmada(s). Reprográmelas o reasígnelas antes de desactivarlo."]);
        }

        $this->cambiarEstado($medico, "INACTIVO", "DESACTIVAR_MEDICO", $request);

        return redirect()->route("medicos.listar")->with("exito", "Médico desactivado");
    }

    // Activar un médico inactivo. El id viaja oculto en el formulario.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $medico = Medico::findOrFail($request->input("id"));

        $this->cambiarEstado($medico, "ACTIVO", "ACTIVAR_MEDICO", $request);

        return redirect()->route("medicos.listar")->with("exito", "Médico activado");
    }

    // CU07 A1 / activar: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(Medico $medico, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($medico, $estado, $accion, $request) {
            $anterior = $medico->estado;
            $medico->update(["estado" => $estado]);

            Bitacora::registrar($accion, "catalogos", $request->user()->id, true, "medicos", $medico->id, "WEB", ["estado" => $anterior], ["estado" => $estado]);
        });
    }

    // Horarios ACTIVOS vigentes (sin fin o que terminan hoy o después) de una asignación médico-especialidad.
    private function horariosVigentes(int $medicoEspecialidadId): int
    {
        return HorarioAtencion::where("medico_especialidad_id", $medicoEspecialidadId)
            ->where("estado", "ACTIVO")
            ->where(fn ($consulta) => $consulta->whereNull("vigente_hasta")->orWhere("vigente_hasta", ">=", today()))
            ->count();
    }

    // CU07 paso 7: código MED-NNN siguiente. El candado de la transacción evita que dos
    // registros simultáneos tomen el mismo número.
    private function siguienteCodigo(): string
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["medicos.codigo"]);
        $ultimo = DB::selectOne('SELECT coalesce(max(substring(codigo from 5)::int), 0) AS n FROM medicos WHERE codigo ~ \'^MED-[0-9]+$\'')->n;

        return sprintf("MED-%03d", $ultimo + 1);
    }

    // Datos del médico para la bitácora (antes y después de un cambio).
    private function foto(Medico $medico): array
    {
        $medico->load(["persona", "medicoEspecialidades"]);

        return [
            "medico" => $medico->only(["codigo", "registro_profesional", "biografia", "estado"]),
            "persona" => Arr::only($medico->persona->toArray(), array_merge(self::CAMPOS_DOCUMENTO, self::CAMPOS_PERSONA)),
            "especialidades" => $medico->medicoEspecialidades->map(fn ($asignacion) => $asignacion->only(["especialidad_id", "es_principal", "estado"]))->values()->all(),
        ];
    }

    // Ids de las especialidades marcadas, como enteros y sin repetir.
    private function idsElegidos(array $datos): array
    {
        return array_values(array_unique(array_map("intval", $datos["especialidades"])));
    }

    // CU07 paso 7: si la base rechaza un dato único (23505), se muestra un mensaje comprensible.
    // Cualquier otro error sigue su curso (página de error sin detalles).
    private function errorUnicidad(QueryException $e)
    {
        if (($e->errorInfo[0] ?? null) === "23505") {
            $mensajes = [
                "ux_personas_documento" => ["numero_documento", "Ya existe una persona con ese documento"],
                "medicos_persona_id_unique" => ["numero_documento", "Esa persona ya está registrada como médico"],
                "medicos_registro_profesional_unique" => ["registro_profesional", "Ya existe un médico con ese registro profesional"],
            ];
            foreach ($mensajes as $indice => [$campo, $mensaje]) {
                if (str_contains($e->getMessage(), $indice)) {
                    return back()->withErrors([$campo => $mensaje])->withInput();
                }
            }
        }

        throw $e;
    }

    // CU07 paso 4: documento sin espacios, puntos ni guiones y en mayúsculas.
    private function normalizarEntrada(Request $request): void
    {
        foreach (["numero_documento", "complemento"] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => Persona::normalizarDocumento($request->input($campo))]);
            }
        }
    }

    // Edad de mayoría (configuraciones_sistema).
    private function edadMayoria(): int
    {
        return (int) ConfiguracionSistema::find("edad_mayoria")->valor;
    }

    // Escapa los comodines de LIKE que escriba el usuario.
    private function escaparLike(string $texto): string
    {
        return str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $texto);
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:medicos,id"]];
    }

    // CU07 paso 4: el documento es obligatorio para un médico.
    private function reglasDocumento(): array
    {
        return [
            "tipo_documento" => ["required", "in:" . implode(",", array_keys(Persona::TIPOS_DOCUMENTO))],
            "numero_documento" => ["required", "string", "max:30"],
            "complemento" => ["nullable", "string", "max:10"],
            "expedido" => ["nullable", "in:" . implode(",", array_keys(Persona::EXPEDIDOS))],
        ];
    }

    // CU07 pasos 4 a 7. $conPersona: validar los datos personales (persona nueva o modificación).
    // Al modificar, $idIgnorar es el propio médico (para el registro profesional único).
    private function reglas(bool $conPersona, ?int $idIgnorar = null): array
    {
        $mayoria = $this->edadMayoria();
        $reglas = [];

        if ($conPersona) {
            $reglas = [
                "nombres" => ["required", "string", "max:255"],
                "apellido_paterno" => ["required", "string", "max:255"],
                "apellido_materno" => ["nullable", "string", "max:255"],
                "fecha_nacimiento" => [
                    "bail", "required", "date", "before_or_equal:today",
                    // CU07 paso 4: el médico debe ser mayor de edad.
                    function (string $atributo, mixed $valor, \Closure $fallar) use ($mayoria) {
                        if (Carbon::parse($valor)->age < $mayoria) {
                            $fallar("El médico debe ser mayor de edad ({$mayoria} años o más).");
                        }
                    },
                ],
                "sexo" => ["required", "in:M,F"],
                "telefono" => ["required", "string", "regex:/^[0-9+\-\s]{7,20}$/"],
                "correo" => ["required", "email", "max:255"],
                "direccion" => ["nullable", "string", "max:255"],
            ];
        }

        return $reglas + [
            "registro_profesional" => [
                "bail", "required", "string", "max:255",
                // CU07 paso 7: único sin distinguir mayúsculas ni espacios en los extremos.
                function (string $atributo, mixed $valor, \Closure $fallar) use ($idIgnorar) {
                    $existe = Medico::whereRaw("lower(btrim(registro_profesional)) = lower(btrim(?))", [$valor])
                        ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
                        ->exists();
                    if ($existe) {
                        $fallar("Ya existe un médico con ese registro profesional");
                    }
                },
            ],
            "biografia" => ["nullable", "string", "max:2000"],
            // CU07 pasos 6 y 7: al menos una especialidad ACTIVA y la principal entre las elegidas.
            "especialidades" => ["required", "array", "min:1"],
            "especialidades.*" => ["integer", Rule::exists("especialidades", "id")->where("estado", "ACTIVO")],
            "principal" => [
                "bail", "required", "integer",
                function (string $atributo, mixed $valor, \Closure $fallar) {
                    if (! in_array((int) $valor, array_map("intval", (array) request()->input("especialidades", [])), true)) {
                        $fallar("La especialidad principal debe estar entre las elegidas.");
                    }
                },
            ],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el médico.",
            "id.integer" => "El médico indicado no es válido.",
            "id.exists" => "El médico indicado no existe.",
            "tipo_documento.required" => "El campo Tipo de documento es obligatorio.",
            "tipo_documento.in" => "El tipo de documento no es válido.",
            "numero_documento.required" => "El campo Número de documento es obligatorio.",
            "numero_documento.max" => "El número de documento no puede superar los 30 caracteres.",
            "complemento.max" => "El complemento no puede superar los 10 caracteres.",
            "expedido.in" => "El lugar de expedición no es válido.",
            "nombres.required" => "El campo Nombres es obligatorio.",
            "nombres.max" => "Los nombres no pueden superar los 255 caracteres.",
            "apellido_paterno.required" => "El campo Apellido paterno es obligatorio.",
            "apellido_paterno.max" => "El apellido paterno no puede superar los 255 caracteres.",
            "apellido_materno.max" => "El apellido materno no puede superar los 255 caracteres.",
            "fecha_nacimiento.required" => "El campo Fecha de nacimiento es obligatorio.",
            "fecha_nacimiento.date" => "La fecha de nacimiento no es válida.",
            "fecha_nacimiento.before_or_equal" => "La fecha de nacimiento no puede ser futura.",
            "sexo.required" => "El campo Sexo es obligatorio.",
            "sexo.in" => "El sexo debe ser M o F.",
            "telefono.required" => "El campo Teléfono es obligatorio.",
            "telefono.regex" => "El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).",
            "correo.required" => "El campo Correo es obligatorio.",
            "correo.email" => "El correo no tiene un formato válido.",
            "correo.max" => "El correo no puede superar los 255 caracteres.",
            "direccion.max" => "La dirección no puede superar los 255 caracteres.",
            "registro_profesional.required" => "El campo Registro profesional es obligatorio.",
            "registro_profesional.max" => "El registro profesional no puede superar los 255 caracteres.",
            "biografia.max" => "La biografía no puede superar los 2000 caracteres.",
            "especialidades.required" => "Todo médico debe ejercer al menos una especialidad activa",
            "especialidades.array" => "Todo médico debe ejercer al menos una especialidad activa",
            "especialidades.min" => "Todo médico debe ejercer al menos una especialidad activa",
            "especialidades.*.integer" => "Una de las especialidades elegidas no es válida.",
            "especialidades.*.exists" => "Una de las especialidades elegidas no existe o no está activa.",
            "principal.required" => "Marque cuál de las especialidades elegidas es la principal.",
            "principal.integer" => "La especialidad principal no es válida.",
        ];
    }
}
