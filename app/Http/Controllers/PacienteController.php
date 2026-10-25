<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\ConfiguracionSistema;
use App\Models\HistoriaClinica;
use App\Models\Paciente;
use App\Models\Persona;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * CU10 Gestionar pacientes y CU11 Buscar paciente (RF02).
 * Buscar y ver: permiso pacientes.buscar. Registrar, modificar, desactivar y activar:
 * permiso pacientes.gestionar. No hay borrado físico (RN11).
 */
class PacienteController extends Controller
{
    // Datos de la persona que se guardan desde el formulario.
    private const CAMPOS_PERSONA = ["tipo_documento", "numero_documento", "complemento", "expedido", "nombres", "apellido_paterno",
        "apellido_materno", "fecha_nacimiento", "sexo", "telefono", "correo", "direccion"];

    private const DOCUMENTO_REPETIDO = "Ya existe una persona con ese documento";

    // CU11 pasos 1 a 5: buscar pacientes por documento, código PAC- o parte del nombre o apellido.
    public function index(Request $request)
    {
        $texto = trim((string) $request->query("q", ""));

        $consulta = Paciente::with("persona")
            ->join("personas", "personas.id", "=", "pacientes.persona_id")
            ->select("pacientes.*");

        if ($texto !== "") {
            // CU11 paso 2: el documento se normaliza (mayúsculas sin espacios, puntos ni guiones) y
            // los nombres con fn_normalizar_texto (sin tildes ni mayúsculas).
            $documento = Persona::normalizarDocumento($texto);
            $patron = "%" . $this->escaparLike($texto) . "%";

            // CU11 paso 3: código, documento (normalizado en los dos lados) y nombre completo, con parámetros.
            $consulta->where(function ($condicion) use ($patron, $documento) {
                $condicion->whereRaw("upper(pacientes.codigo) LIKE upper(?)", [$patron])
                    ->orWhereRaw("fn_normalizar_texto(personas.nombres || ' ' || personas.apellido_paterno || ' ' || coalesce(personas.apellido_materno, '')) LIKE fn_normalizar_texto(?)", [$patron]);
                if ($documento !== null) {
                    $condicion->orWhereRaw("regexp_replace(upper(personas.numero_documento), '[\\s.\\-]', '', 'g') LIKE ?", ["%" . $this->escaparLike($documento) . "%"]);
                }
            })
                ->orderBy("personas.apellido_paterno")
                ->orderBy("personas.nombres");
        } else {
            // Sin texto: todos, los más recientes primero.
            $consulta->orderByDesc("pacientes.fecha_registro")->orderByDesc("pacientes.id");
        }

        // CU11 A1: un médico solo ve a los pacientes con los que tiene o tuvo alguna cita.
        if ($medicoId = $this->medicoDelUsuario($request)) {
            $consulta->whereExists($this->citasConMedico($medicoId));
        }

        // CU11 paso 4: lista paginada (15).
        $pacientes = $consulta->paginate(15)->withQueryString();

        return view("pacientes.index", [
            "pacientes" => $pacientes,
            "texto" => $texto,
            "edadMayoria" => $this->edadMayoria(),
        ]);
    }

    // CU10 pasos 2 a 9: registrar un paciente.
    public function store(Request $request)
    {
        // CU10 pasos 4 a 6: normalizar y validar.
        $this->normalizarEntrada($request);
        $datos = $this->validar($request);

        // CU10 paso 4: si la persona ya existe y es paciente, no se registra de nuevo.
        $persona = null;
        if (! empty($datos["numero_documento"])) {
            $persona = Persona::buscarPorDocumento($datos["tipo_documento"], $datos["numero_documento"], $datos["complemento"] ?? null);

            if ($persona?->paciente) {
                return back()->withErrors(["numero_documento" => self::DOCUMENTO_REPETIDO])->withInput()
                    ->with("paciente_existente", ["id" => $persona->paciente->id, "codigo" => $persona->paciente->codigo]);
            }
        }

        // La recepcionista que registra (si el usuario tiene ese perfil; si no, null).
        $recepcionistaId = $request->user()->persona?->recepcionista?->id;

        try {
            // CU10 paso 7: todo en UNA transacción.
            $paciente = DB::transaction(function () use ($persona, $datos, $recepcionistaId, $request) {
                // CU10 A1: si la persona existe (por ejemplo, es médico), se reutiliza.
                $persona ??= Persona::create(Arr::only($datos, self::CAMPOS_PERSONA));

                $paciente = Paciente::create([
                    "persona_id" => $persona->id,
                    "recepcionista_registra_id" => $recepcionistaId,
                    "codigo" => $this->siguienteCodigo(),
                    "estado" => "ACTIVO",
                ]);

                // CU10 paso 7: historia clínica abierta hoy.
                HistoriaClinica::create([
                    "paciente_id" => $paciente->id,
                    "fecha_apertura" => today()->toDateString(),
                    "grupo_sanguineo" => "DESCONOCIDO",
                ]);

                Bitacora::registrar("CREAR_PACIENTE", "pacientes", $request->user()->id, true, "pacientes", $paciente->id, "WEB", null, $this->foto($paciente));

                return $paciente;
            });
        } catch (QueryException $e) {
            return $this->errorDocumento($e);
        }

        $paciente->load("persona");
        $avisos = [];
        if ($persona !== null) {
            $avisos[] = "Se reutilizaron los datos de la persona ya registrada con ese documento.";
        }
        // CU10 paso 8: un menor necesita a su responsable legal antes de recibir citas.
        if ($paciente->persona->edad < $this->edadMayoria()) {
            $avisos[] = "El paciente es menor de edad: registre a su responsable legal en Vínculos antes de programarle citas.";
        }

        // CU10 paso 9: a la ficha del paciente.
        $respuesta = redirect()->route("pacientes.mostrar", $paciente->id)->with("exito", "Paciente registrado ({$paciente->codigo})");

        return $avisos ? $respuesta->with("aviso", implode(" ", $avisos)) : $respuesta;
    }

    // CU11 paso 5 / CU10 A4: ficha del paciente y sus citas (RF02: consultar sus citas anteriores).
    public function show(Request $request, $id)
    {
        // El id llega en la URL: si no es un número, la página no existe.
        abort_unless(ctype_digit((string) $id), 404);

        $paciente = Paciente::with(["persona", "recepcionista.persona", "historiaClinica"])->findOrFail($id);

        // CU11 A1: un médico solo ve a los pacientes con los que tiene o tuvo alguna cita.
        if ($medicoId = $this->medicoDelUsuario($request)) {
            $tieneCita = Paciente::where("pacientes.id", $paciente->id)->whereExists($this->citasConMedico($medicoId))->exists();
            abort_unless($tieneCita, 403);
        }

        // CU10 A4: todas sus citas (incluidas las canceladas), de la más reciente a la más antigua.
        $citas = Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->join("horarios_atencion", "horarios_atencion.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
            ->where("citas.paciente_id", $paciente->id)
            ->select("citas.codigo", "citas.estado", "citas.fecha_registro", "fichas.fecha", "fichas.hora_inicio",
                "personas.nombres as medico_nombres", "personas.apellido_paterno as medico_apellido", "especialidades.nombre as especialidad")
            ->orderByDesc("fichas.fecha")
            ->orderByDesc("fichas.hora_inicio")
            ->get();

        return view("pacientes.show", [
            "paciente" => $paciente,
            "citas" => $citas,
            "edadMayoria" => $this->edadMayoria(),
        ]);
    }

    // CU10 A2: modificar los datos personales (también el documento). El id viaja oculto.
    public function update(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $paciente = Paciente::with("persona")->findOrFail($request->input("id"));

        $this->normalizarEntrada($request);
        $datos = $this->validar($request);

        // CU10 A2: el documento sigue siendo único, sin contar a la propia persona.
        if (! empty($datos["numero_documento"])) {
            $otra = Persona::buscarPorDocumento($datos["tipo_documento"], $datos["numero_documento"], $datos["complemento"] ?? null);
            if ($otra !== null && $otra->id !== $paciente->persona_id) {
                return back()->withErrors(["numero_documento" => self::DOCUMENTO_REPETIDO])->withInput();
            }
        }

        $anteriores = $this->foto($paciente);

        try {
            DB::transaction(function () use ($paciente, $datos, $anteriores, $request) {
                $paciente->persona->update(Arr::only($datos, self::CAMPOS_PERSONA));

                Bitacora::registrar("MODIFICAR_PACIENTE", "pacientes", $request->user()->id, true, "pacientes", $paciente->id, "WEB", $anteriores, $this->foto($paciente));
            });
        } catch (QueryException $e) {
            return $this->errorDocumento($e);
        }

        return redirect()->route("pacientes.mostrar", $paciente->id)->with("exito", "Paciente actualizado");
    }

    // CU10 A3: desactivar (RN11: no se borra). El id viaja oculto en el formulario.
    public function delete(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $paciente = Paciente::findOrFail($request->input("id"));

        $this->cambiarEstado($paciente, "INACTIVO", "DESACTIVAR_PACIENTE", $request);

        return back()->with("exito", "Paciente desactivado");
    }

    // CU10 A3: activar un paciente inactivo. El id viaja oculto en el formulario.
    public function activar(Request $request)
    {
        $request->validate($this->reglaId(), $this->mensajes());
        $paciente = Paciente::findOrFail($request->input("id"));

        $this->cambiarEstado($paciente, "ACTIVO", "ACTIVAR_PACIENTE", $request);

        return back()->with("exito", "Paciente activado");
    }

    // CU10 A3: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(Paciente $paciente, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($paciente, $estado, $accion, $request) {
            $anterior = $paciente->estado;
            $paciente->update(["estado" => $estado]);

            Bitacora::registrar($accion, "pacientes", $request->user()->id, true, "pacientes", $paciente->id, "WEB", ["estado" => $anterior], ["estado" => $estado]);
        });
    }

    // CU11 A1: id del médico si la persona del usuario tiene perfil de médico.
    private function medicoDelUsuario(Request $request): ?int
    {
        return $request->user()->persona?->medico?->id;
    }

    // CU11 A1: subconsulta «el paciente tiene o tuvo alguna cita con este médico»
    // (citas → fichas → horarios → medicos_especialidades).
    private function citasConMedico(int $medicoId): \Closure
    {
        return fn ($consulta) => $consulta->selectRaw("1")
            ->from("citas")
            ->join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->join("horarios_atencion", "horarios_atencion.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->whereColumn("citas.paciente_id", "pacientes.id")
            ->where("medicos_especialidades.medico_id", $medicoId);
    }

    // CU10 paso 7: código PAC-NNNNNN siguiente. El candado de la transacción evita que dos
    // registros simultáneos tomen el mismo número.
    private function siguienteCodigo(): string
    {
        DB::select("SELECT pg_advisory_xact_lock(hashtext(?))", ["pacientes.codigo"]);
        $ultimo = DB::selectOne('SELECT coalesce(max(substring(codigo from 5)::int), 0) AS n FROM pacientes WHERE codigo ~ \'^PAC-[0-9]+$\'')->n;

        return sprintf("PAC-%06d", $ultimo + 1);
    }

    // Datos del paciente para la bitácora (antes y después de un cambio).
    private function foto(Paciente $paciente): array
    {
        $paciente->refresh()->load("persona");

        return [
            "paciente" => $paciente->only(["codigo", "estado", "recepcionista_registra_id"]),
            "persona" => Arr::only($paciente->persona->toArray(), self::CAMPOS_PERSONA),
        ];
    }

    // CU10 paso 7: si la base rechaza el documento (23505 en ux_personas_documento), se muestra
    // el mismo mensaje. Cualquier otro error sigue su curso (página de error sin detalles).
    private function errorDocumento(QueryException $e)
    {
        if (($e->errorInfo[0] ?? null) === "23505" && str_contains($e->getMessage(), "ux_personas_documento")) {
            return back()->withErrors(["numero_documento" => self::DOCUMENTO_REPETIDO])->withInput();
        }

        throw $e;
    }

    // CU10 paso 4: documento en mayúsculas sin espacios, puntos ni guiones.
    // Sin número no se guarda tipo, complemento ni lugar de expedición (menor sin documento).
    private function normalizarEntrada(Request $request): void
    {
        foreach (["numero_documento", "complemento"] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => Persona::normalizarDocumento($request->input($campo))]);
            }
        }
        if (empty($request->input("numero_documento"))) {
            $request->merge(["tipo_documento" => null, "complemento" => null, "expedido" => null]);
        }
    }

    // CU10 pasos 5 y 6: validación de los datos, más la regla de edad y documento.
    private function validar(Request $request): array
    {
        $validador = Validator::make($request->all(), $this->reglas(), $this->mensajes());

        // CU10: solo un menor de edad puede omitir el número de documento.
        $validador->after(function ($validador) use ($request) {
            if ($validador->errors()->has("fecha_nacimiento") || ! empty($request->input("numero_documento"))) {
                return;
            }
            if (Carbon::parse($request->input("fecha_nacimiento"))->age >= $this->edadMayoria()) {
                $validador->errors()->add("numero_documento", "Solo un menor de edad puede registrarse sin número de documento");
            }
        });

        return $validador->validate();
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
        return ["id" => ["required", "integer", "exists:pacientes,id"]];
    }

    // CU10 pasos 4 y 5: reglas de los datos personales.
    private function reglas(): array
    {
        return [
            // Si hay número debe haber tipo.
            "tipo_documento" => ["nullable", "required_with:numero_documento", "in:" . implode(",", array_keys(Persona::TIPOS_DOCUMENTO))],
            "numero_documento" => ["nullable", "string", "max:30"],
            "complemento" => ["nullable", "string", "max:10"],
            "expedido" => ["nullable", "in:" . implode(",", array_keys(Persona::EXPEDIDOS))],
            "nombres" => ["required", "string", "max:255"],
            "apellido_paterno" => ["required", "string", "max:255"],
            "apellido_materno" => ["nullable", "string", "max:255"],
            "fecha_nacimiento" => ["bail", "required", "date", "before_or_equal:today"],
            "sexo" => ["required", "in:M,F"],
            "telefono" => ["required", "string", "regex:/^[0-9+\-\s]{7,20}$/"],
            "correo" => ["nullable", "email", "max:255"],
            "direccion" => ["nullable", "string", "max:255"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el paciente.",
            "id.integer" => "El paciente indicado no es válido.",
            "id.exists" => "El paciente indicado no existe.",
            "tipo_documento.required_with" => "Indique el tipo de documento.",
            "tipo_documento.in" => "El tipo de documento no es válido.",
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
            "correo.email" => "El correo no tiene un formato válido.",
            "correo.max" => "El correo no puede superar los 255 caracteres.",
            "direccion.max" => "La dirección no puede superar los 255 caracteres.",
        ];
    }
}
