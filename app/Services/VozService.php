<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Paciente;
use App\Models\Usuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CU39 Ejecutar comando de voz (RF-40). Actor: Usuario con voz.usar; actor secundario: Servicio de IA
 * (Google Gemini). La IA solo INTERPRETA: el servidor no confía en su respuesta, valida la intención contra
 * los permisos del usuario, resuelve los datos contra la base y guarda la interpretación en la sesión.
 * Nada se ejecuta sin la confirmación del usuario; la ejecución usa los servicios de cada CU (CitaService)
 * con los MISMOS permisos.
 */
class VozService
{
    // Clave de la sesión con la interpretación pendiente de confirmar (una sola a la vez).
    private const SESION = "voz.pendiente";

    // CU39 paso 2: una interpretación sin confirmar vence a los 5 minutos.
    private const MINUTOS_CONFIRMAR = 5;

    // Largo máximo del texto que se envía a la IA.
    public const MAX_TEXTO = 500;

    public const NO_DISPONIBLE = "El servicio de IA no está disponible en este momento. Use las pantallas del sistema o inténtelo más tarde.";

    public function __construct(private CitaService $citas)
    {
    }

    /**
     * CU39 intenciones: cada una con el permiso que ya usa su CU. Solo las que el usuario puede usar se
     * ofrecen a la IA (paso 3) y se aceptan en su respuesta (paso 5).
     */
    public function intencionesPermitidas(Usuario $usuario): array
    {
        $medico = $usuario->persona?->medico;
        $intenciones = [];
        if ($usuario->tienePermiso("fichas.consultar")) {
            $intenciones["consultar_disponibilidad"] = "consultar fichas disponibles. parametros: {\"especialidad\": nombre, \"fecha\": \"AAAA-MM-DD\" opcional}";
        }
        if ($usuario->tienePermiso("agenda.consultar") || ($usuario->tienePermiso("agenda.propia") && $medico) || $usuario->tienePermiso("citas.historial_propio")) {
            $intenciones["ver_citas"] = "ver sus citas o la agenda de un día. parametros: {\"fecha\": \"AAAA-MM-DD\" opcional}";
        }
        if ($usuario->tienePermiso("citas.programar")) {
            $intenciones["programar_cita"] = "programar una cita. parametros: {\"especialidad\": nombre, \"medico\": nombre opcional, \"fecha\": \"AAAA-MM-DD\", \"hora\": \"HH:MM\" opcional, \"paciente\": nombre, código PAC- o documento del paciente}";
        } elseif ($usuario->tienePermiso("citas.autoservicio")) {
            $intenciones["programar_cita"] = "reservar una cita para sí mismo. parametros: {\"especialidad\": nombre, \"medico\": nombre opcional, \"fecha\": \"AAAA-MM-DD\", \"hora\": \"HH:MM\" opcional}";
        }
        if ($usuario->tienePermiso("citas.cancelar") || $usuario->tienePermiso("citas.autoservicio")) {
            $intenciones["cancelar_cita"] = "cancelar una cita. parametros: {\"codigo\": \"CM-000000\", \"motivo\": texto}";
        }
        // CU30: quien puede ver cardex (médico ACTIVO con historia.consultar, o el paciente con historia.propia).
        if (Paciente::medicoClinico($usuario) !== null || $usuario->tienePermiso("historia.propia")) {
            $intenciones["ver_cardex"] = "abrir el cardex (historia clínica) de un paciente. parametros: {\"paciente\": nombre, código PAC- o documento; "
                . "omítelo si pide el suyo}. Ejemplos: \"abre el cardex de Pedro Mamani\" → {\"intencion\": \"ver_cardex\", \"parametros\": {\"paciente\": \"Pedro Mamani\"}}; "
                . "\"muéstrame mi cardex\" → {\"intencion\": \"ver_cardex\", \"parametros\": {}}";
        }

        return $intenciones;
    }

    /**
     * CU39 pasos 2 a 7: interpreta el texto con el Servicio de IA y devuelve la confirmación legible.
     * Siempre deja una fila INTERPRETAR_VOZ (canal VOZ, con la transcripción; éxito o fallo, nunca la clave).
     * Lanza ReglaNegocioException con el mensaje para el usuario (A1 a A4); no ejecuta nada.
     */
    public function interpretar(string $texto, Usuario $usuario): array
    {
        $texto = trim($texto);
        $intenciones = $this->intencionesPermitidas($usuario);

        try {
            // CU39 A1: sin ninguna intención disponible para su rol.
            if ($intenciones === []) {
                throw new ReglaNegocioException("No hay comandos de voz disponibles para su cuenta.");
            }

            // CU39 pasos 3 y 4: el Servicio de IA devuelve {intencion, parametros, resumen}.
            $respuesta = $this->consultarIa($texto, $intenciones);

            // CU39 paso 5 / A3: la intención debe ser una de las permitidas (el servidor no confía en la IA).
            $intencion = (string) ($respuesta["intencion"] ?? "");
            if (! array_key_exists($intencion, $intenciones)) {
                throw new ReglaNegocioException("No entendí un comando que pueda ejecutar. Por ejemplo: «quiero una cita de medicina general para el lunes».");
            }

            // CU39 paso 6 / A4: los parámetros resueltos contra la base; si falta algo, se dice qué.
            $parametros = is_array($respuesta["parametros"] ?? null) ? $respuesta["parametros"] : [];
            $accion = match ($intencion) {
                "consultar_disponibilidad" => $this->resolverDisponibilidad($parametros),
                "ver_citas" => $this->resolverVerCitas($parametros, $usuario),
                "programar_cita" => $this->resolverProgramar($parametros, $usuario),
                "cancelar_cita" => $this->resolverCancelar($parametros, $usuario),
                "ver_cardex" => $this->resolverCardex($parametros, $usuario),
            };
        } catch (ReglaNegocioException $e) {
            $this->registrar($usuario, $texto, false, ["error" => $e->getMessage()]);
            throw $e;
        }

        // CU39 paso 7: la interpretación resuelta queda en la sesión con un token (una sola a la vez).
        $token = Str::random(40);
        session()->put(self::SESION, $accion + [
            "token" => $token,
            "intencion" => $intencion,
            "transcripcion" => $texto,
            "vence" => now()->addMinutes(self::MINUTOS_CONFIRMAR)->timestamp,
        ]);
        $this->registrar($usuario, $texto, true, ["intencion" => $intencion, "confirmacion" => $accion["confirmacion"]]);

        return ["token" => $token, "confirmacion" => $accion["confirmacion"]];
    }

    /**
     * CU39 pasos 8 y 9: ejecuta la interpretación confirmada (se toma de la sesión una sola vez) con los
     * servicios de cada CU y los mismos permisos. Devuelve [url, mensaje]. A5: token distinto o vencido.
     */
    public function ejecutar(string $token, Usuario $usuario): array
    {
        $accion = session()->pull(self::SESION);
        if (! is_array($accion) || ! hash_equals((string) $accion["token"], $token) || $accion["vence"] < now()->timestamp) {
            throw new ReglaNegocioException("La confirmación ya no es válida. Repita el comando.");
        }
        // Los permisos pudieron cambiar entre la interpretación y la confirmación.
        if (! array_key_exists($accion["intencion"], $this->intencionesPermitidas($usuario))) {
            throw new AuthorizationException("No puede ejecutar este comando.");
        }

        return match ($accion["intencion"]) {
            // CU39 paso 8 a), b) y e): la pantalla del CU con los filtros puestos o el cardex (CU30 vuelve a revisar
            // el acceso y registra CONSULTAR_CARDEX).
            "consultar_disponibilidad", "ver_citas", "ver_cardex" => [$accion["url"], null],
            // CU39 paso 8 c): CU15 con canal VOZ (CitaService valida todo otra vez dentro de su transacción).
            "programar_cita" => $this->ejecutarProgramar($accion, $usuario),
            // CU39 paso 8 d): CU17 con canal VOZ.
            "cancelar_cita" => $this->ejecutarCancelar($accion, $usuario),
        };
    }

    // CU39 paso 3: modelo configurado (GEMINI_MODEL o el de config/services.php).
    public function modelo(): string
    {
        return (string) config("services.gemini.modelo");
    }

    /**
     * CU39 pasos 3 y 4: llamada HTTP a generateContent. La clave va en el encabezado x-goog-api-key (no en la
     * URL, para que no quede en registros). JSON, temperatura baja, 15 s. A2: sin clave, error HTTP o respuesta
     * que no es el JSON esperado → NO_DISPONIBLE.
     */
    private function consultarIa(string $texto, array $intenciones): array
    {
        $clave = config("services.gemini.clave");
        if (! $clave) {
            throw new ReglaNegocioException(self::NO_DISPONIBLE);
        }

        try {
            $respuesta = Http::timeout((int) config("services.gemini.timeout", 15))
                ->withHeaders(["x-goog-api-key" => $clave])
                ->acceptJson()
                ->post(config("services.gemini.url") . "/models/" . $this->modelo() . ":generateContent", [
                    "systemInstruction" => ["parts" => [["text" => $this->prompt($intenciones)]]],
                    "contents" => [["role" => "user", "parts" => [["text" => $texto]]]],
                    "generationConfig" => ["responseMimeType" => "application/json", "temperature" => 0.1],
                ]);
        } catch (\Throwable $e) {
            Log::warning("CU39: el Servicio de IA no respondió: " . get_class($e));
            throw new ReglaNegocioException(self::NO_DISPONIBLE);
        }

        $json = json_decode((string) $respuesta->json("candidates.0.content.parts.0.text"), true);
        if (! $respuesta->successful() || ! is_array($json) || ! isset($json["intencion"])) {
            Log::warning("CU39: respuesta no válida del Servicio de IA (HTTP {$respuesta->status()}).");
            throw new ReglaNegocioException(self::NO_DISPONIBLE);
        }

        return $json;
    }

    // CU39 paso 3: instrucciones para la IA: hoy (America/La_Paz), especialidades ACTIVAS y SOLO las intenciones permitidas.
    private function prompt(array $intenciones): string
    {
        $hoy = now();
        $especialidades = Especialidad::where("estado", "ACTIVO")->orderBy("nombre")->pluck("nombre")->implode(", ");
        $lista = collect($intenciones)->map(fn ($descripcion, $nombre) => "- {$nombre}: {$descripcion}")->implode("\n");

        return "Eres el asistente de comandos de voz de la Clínica Vida Salud (Bolivia). Interpreta la orden del usuario.\n"
            . "Hoy es " . mb_strtolower(AgendaService::DIAS[$hoy->dayOfWeekIso]) . " " . $hoy->toDateString() . " (zona America/La_Paz).\n"
            . "Especialidades disponibles: {$especialidades}.\n"
            . "Intenciones permitidas (usa solo estas):\n{$lista}\n"
            . "Reglas: fechas en AAAA-MM-DD (\"el lunes\" es el próximo lunes; \"mañana\" es hoy + 1); horas en HH:MM de 24 h; "
            . "la especialidad con el nombre exacto de la lista; omite los parámetros que el usuario no dijo (no los inventes). "
            . "Si la orden no corresponde a ninguna intención permitida, usa \"intencion\": \"desconocida\".\n"
            . "Responde SOLO un objeto JSON: {\"intencion\": string, \"parametros\": object, \"resumen\": string en español}.";
    }

    // CU39 paso 6 a): especialidad (obligatoria) y fecha (hoy por defecto) → filtros de CU14.
    private function resolverDisponibilidad(array $p): array
    {
        $especialidad = $this->especialidad($p["especialidad"] ?? null);
        $fecha = $this->fecha($p["fecha"] ?? null, false) ?? today();

        return [
            "url" => route("fichas.disponibilidad", [
                "especialidad_id" => $especialidad->id,
                "desde" => $fecha->toDateString(),
                "hasta" => $fecha->copy()->addDays(6)->toDateString(),
            ]),
            "confirmacion" => "Ver las fichas disponibles de {$especialidad->nombre} del {$fecha->format('d/m/Y')} al {$fecha->copy()->addDays(6)->format('d/m/Y')}. ¿Confirmar?",
        ];
    }

    // CU39 paso 6 b): agenda del día (CU19) para médico o recepción; historial propio (CU20) para el paciente.
    private function resolverVerCitas(array $p, Usuario $usuario): array
    {
        $fecha = $this->fecha($p["fecha"] ?? null, false);
        $agenda = $usuario->tienePermiso("agenda.consultar") || ($usuario->tienePermiso("agenda.propia") && $usuario->persona?->medico);

        if ($agenda) {
            $fecha ??= today();

            return [
                "url" => route("citas.agenda", ["fecha" => $fecha->toDateString()]),
                "confirmacion" => "Ver la agenda del {$fecha->format('d/m/Y')}. ¿Confirmar?",
            ];
        }

        return [
            "url" => route("historiales-cita.listar", $fecha ? ["desde" => $fecha->toDateString(), "hasta" => $fecha->toDateString()] : []),
            "confirmacion" => $fecha ? "Ver sus citas del {$fecha->format('d/m/Y')}. ¿Confirmar?" : "Ver su historial de citas. ¿Confirmar?",
        ];
    }

    /**
     * CU39 paso 6 c): especialidad, médico (opcional), fecha (obligatoria), hora (opcional) y paciente
     * (obligatorio solo para recepción, buscado como CU11; el paciente se reserva a sí mismo). Se elige la
     * primera ficha disponible (CitaService::fichasDisponibles) en esa fecha desde esa hora.
     */
    private function resolverProgramar(array $p, Usuario $usuario): array
    {
        $recepcion = $usuario->tienePermiso("citas.programar");
        $especialidad = $this->especialidad($p["especialidad"] ?? null);
        $fecha = $this->fecha($p["fecha"] ?? null, true);
        $hora = $this->hora($p["hora"] ?? null);

        // Paciente: recepción lo indica (CU11: código, nombre o documento); el paciente es él mismo (CU15 A1).
        if ($recepcion) {
            $buscado = trim((string) ($p["paciente"] ?? ""));
            if ($buscado === "") {
                throw new ReglaNegocioException("Falta el paciente: diga su nombre, su código PAC- o su documento.");
            }
            $pacientes = Paciente::buscar($buscado)->where("pacientes.estado", "ACTIVO")->limit(3)->get();
            if ($pacientes->isEmpty()) {
                throw new ReglaNegocioException("No encontré un paciente activo con «{$buscado}».");
            }
            if ($pacientes->count() > 1) {
                throw new ReglaNegocioException("Hay varios pacientes con «{$buscado}» (" . $pacientes->map(fn ($x) => "{$x->persona->nombre_completo} {$x->codigo}")->implode(", ") . "). Diga el código PAC-.");
            }
            $paciente = $pacientes->first();
        } else {
            $paciente = $usuario->persona?->paciente;
            if ($paciente === null) {
                throw new ReglaNegocioException("Su cuenta no tiene perfil de paciente para reservar citas.");
            }
        }

        // Médicos ACTIVOS de la especialidad (o el nombrado).
        $medicos = Medico::with("persona")->where("estado", "ACTIVO")
            ->whereHas("especialidades", fn ($e) => $e->where("especialidades.id", $especialidad->id)->where("medicos_especialidades.estado", "ACTIVO"))
            ->get();
        $nombreMedico = trim((string) ($p["medico"] ?? ""));
        if ($nombreMedico !== "") {
            $normalizado = $this->normalizar(preg_replace('/^(dr|dra|doctor|doctora)\.?\s+/i', "", $nombreMedico));
            $medicos = $medicos->filter(fn ($m) => str_contains($this->normalizar($m->persona->nombre_completo), $normalizado))->values();
            if ($medicos->isEmpty()) {
                throw new ReglaNegocioException("No encontré a «{$nombreMedico}» entre los médicos de {$especialidad->nombre}.");
            }
        }

        // La primera ficha disponible de esa fecha, desde la hora pedida (CU14 / RF-21).
        $ficha = $medicos->flatMap(fn ($m) => $this->citas->fichasDisponibles($m->id, $especialidad->id, $fecha))
            ->filter(fn ($f) => $hora === null || substr($f->hora_inicio, 0, 5) >= $hora)
            ->sortBy(fn ($f) => $f->hora_inicio)
            ->first();
        if ($ficha === null) {
            throw new ReglaNegocioException("No hay fichas disponibles de {$especialidad->nombre}" . ($nombreMedico !== "" ? " con {$nombreMedico}" : "")
                . " el {$fecha->format('d/m/Y')}" . ($hora ? " desde las {$hora}" : "") . ". Pruebe otra fecha.");
        }
        $medico = $medicos->firstWhere("id", $ficha->medico_id);

        return [
            "paciente_id" => $paciente->id,
            "ficha_id" => $ficha->id,
            "origen" => $recepcion ? CitaService::ORIGEN_RECEPCION : CitaService::ORIGEN_AUTOSERVICIO,
            "confirmacion" => "Programar cita de {$especialidad->nombre} con " . CitaService::tituloMedico($medico->persona)
                . " el {$fecha->format('d/m/Y')} a las " . substr($ficha->hora_inicio, 0, 5)
                . " para {$paciente->persona->nombre_completo}. ¿Confirmar?",
        ];
    }

    // CU39 paso 6 d): la cita por su código (CM-NNNNNN) y el motivo (obligatorio, ck_citas_cancelacion).
    private function resolverCancelar(array $p, Usuario $usuario): array
    {
        $numero = preg_replace('/\D/', "", (string) ($p["codigo"] ?? ""));
        if ($numero === "") {
            throw new ReglaNegocioException("Falta el código de la cita (por ejemplo, CM-000045).");
        }
        $codigo = sprintf("CM-%06d", (int) $numero);
        $cita = Cita::with(["paciente.persona", "ficha"])->where("codigo", $codigo)->first();
        // Misma regla que CU17: recepción con citas.cancelar o el dueño (o su responsable) con citas.autoservicio.
        $puede = $cita && ($usuario->tienePermiso("citas.cancelar") || $cita->esDeLaPersona($usuario->persona_id));
        if (! $puede) {
            throw new ReglaNegocioException("No encontré la cita {$codigo} entre las que puede cancelar.");
        }
        $motivo = trim((string) ($p["motivo"] ?? ""));
        if ($motivo === "") {
            throw new ReglaNegocioException("Falta el motivo de la cancelación.");
        }

        return [
            "cita_id" => $cita->id,
            "motivo" => mb_substr($motivo, 0, 255),
            "confirmacion" => "Cancelar la cita {$cita->codigo} de {$cita->paciente->persona->nombre_completo} del "
                . Carbon::parse($cita->ficha->fecha)->format("d/m/Y") . " a las " . substr($cita->ficha->hora_inicio, 0, 5)
                . ". Motivo: {$motivo}. ¿Confirmar?",
        ];
    }

    /**
     * CU39 paso 6 e): el paciente del cardex, buscado como CU11 (Paciente::buscar) y validado con la regla de CU30
     * (Paciente::accesoClinico): el médico, pacientes con los que tiene o tuvo cita; el paciente, el suyo o un menor
     * a su cargo (sin nombre, el suyo). Solo se consideran los pacientes a los que tiene acceso: «sin acceso» y
     * «sin coincidencia» dan el mismo mensaje (no revela datos de otros pacientes). Varias → que precise.
     */
    private function resolverCardex(array $p, Usuario $usuario): array
    {
        $buscado = trim((string) ($p["paciente"] ?? ""));
        if ($buscado === "") {
            $paciente = $usuario->tienePermiso("historia.propia") ? $usuario->persona?->paciente : null;
            if ($paciente === null || $paciente->accesoClinico($usuario) === null) {
                throw new ReglaNegocioException("Falta el paciente: diga su nombre, su código PAC- o su documento.");
            }
        } else {
            $pacientes = Paciente::buscar($buscado)->limit(50)->get()
                ->filter(fn (Paciente $candidato) => $candidato->accesoClinico($usuario) !== null)->values();
            if ($pacientes->isEmpty()) {
                throw new ReglaNegocioException("No encontré a «{$buscado}» entre los pacientes cuyo cardex puede ver.");
            }
            if ($pacientes->count() > 1) {
                throw new ReglaNegocioException("Hay varios pacientes con «{$buscado}» (" . $pacientes->take(5)->map(fn ($x) => "{$x->persona->nombre_completo} {$x->codigo}")->implode(", ") . "). Diga el código PAC-.");
            }
            $paciente = $pacientes->first();
        }

        return [
            "url" => route("historias-clinicas.mostrar", $paciente->id),
            "confirmacion" => "Abrir el cardex de {$paciente->persona->nombres} {$paciente->persona->apellido_paterno} ({$paciente->codigo}). ¿Confirmar?",
        ];
    }

    // CU39 paso 8 c): CitaService::programar con canal VOZ y la transcripción en la bitácora.
    private function ejecutarProgramar(array $a, Usuario $usuario): array
    {
        $cita = $this->citas->programar(["paciente_id" => $a["paciente_id"], "ficha_id" => $a["ficha_id"]], $usuario, $a["origen"], "VOZ", $a["transcripcion"]);

        return [route("citas.mostrar", $cita->id), "Cita {$cita->codigo} registrada por voz."];
    }

    // CU39 paso 8 d): CitaService::cancelar con canal VOZ (autoservicio si no tiene citas.cancelar, como CU17).
    private function ejecutarCancelar(array $a, Usuario $usuario): array
    {
        $cita = $this->citas->cancelar(Cita::findOrFail($a["cita_id"]), $a["motivo"], $usuario,
            ! $usuario->tienePermiso("citas.cancelar"), "VOZ", $a["transcripcion"]);

        return [route("citas.mostrar", $cita->id), "Cita {$cita->codigo} cancelada por voz. El horario quedó disponible."];
    }

    // CU39 paso 6: especialidad ACTIVA por nombre (sin tildes ni mayúsculas; exacto o que lo contenga).
    private function especialidad(?string $nombre): Especialidad
    {
        $nombre = trim((string) $nombre);
        if ($nombre === "") {
            throw new ReglaNegocioException("Falta la especialidad.");
        }
        $activas = Especialidad::where("estado", "ACTIVO")->get();
        $buscado = $this->normalizar($nombre);
        $especialidad = $activas->first(fn ($e) => $this->normalizar($e->nombre) === $buscado)
            ?? $activas->first(fn ($e) => str_contains($this->normalizar($e->nombre), $buscado));
        if ($especialidad === null) {
            throw new ReglaNegocioException("No encontré la especialidad «{$nombre}».");
        }

        return $especialidad;
    }

    // Fecha AAAA-MM-DD válida y no pasada (null si no se dijo y no es obligatoria).
    private function fecha($valor, bool $obligatoria): ?Carbon
    {
        $valor = trim((string) $valor);
        if ($valor === "") {
            if ($obligatoria) {
                throw new ReglaNegocioException("Falta la fecha de la cita (por ejemplo, «el lunes» o «el 10 de noviembre»).");
            }

            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) || ! checkdate((int) substr($valor, 5, 2), (int) substr($valor, 8, 2), (int) substr($valor, 0, 4))) {
            throw new ReglaNegocioException("No entendí la fecha «{$valor}».");
        }
        $fecha = Carbon::parse($valor)->startOfDay();
        if ($obligatoria && $fecha->lt(today())) {
            throw new ReglaNegocioException("La fecha {$fecha->format('d/m/Y')} ya pasó.");
        }

        return $fecha;
    }

    // Hora HH:MM de 24 h (null si no se dijo).
    private function hora($valor): ?string
    {
        $valor = trim((string) $valor);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valor) ? $valor : null;
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim(Str::ascii($texto)));
    }

    // CU39 paso 7 / A2: bitácora INTERPRETAR_VOZ, canal VOZ, con la transcripción (nunca la clave).
    private function registrar(Usuario $usuario, string $texto, bool $exito, array $datos): void
    {
        DB::transaction(fn () => Bitacora::registrar("INTERPRETAR_VOZ", "voz", $usuario->id, $exito, null, null, "VOZ", null,
            $datos + ["modelo" => $this->modelo()], $texto));
    }
}
