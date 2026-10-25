<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ConfiguracionSistema;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Recepcionista;
use App\Models\VinculoPaciente;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CU13 Gestionar vínculos del paciente (§3.4: contacto de emergencia; RN16: responsable legal).
 * Recepción: vinculos.gestionar (registrar, modificar, desactivar) y vinculos.verificar (verificar).
 * A1: el propio paciente (perfil.gestionar) ve y registra SUS contactos, que quedan sin verificar.
 * No hay borrado físico (RN11): desactivar cambia el estado a INACTIVO.
 */
class VinculoPacienteController extends Controller
{
    // Datos de la persona vinculada que se guardan desde el formulario (personas/_campos).
    private const CAMPOS_PERSONA = ["tipo_documento", "numero_documento", "complemento", "expedido", "nombres", "apellido_paterno",
        "apellido_materno", "fecha_nacimiento", "sexo", "telefono", "correo", "direccion"];

    private const CAMPOS_DOCUMENTO = ["tipo_documento", "numero_documento", "complemento", "expedido"];

    private const RESPONSABLE_INVALIDO = "El responsable legal debe ser mayor de edad y tener documento de identidad";

    // CU13 pasos 1-2: datos del paciente y lista de sus vínculos. Sin paciente_id, los del propio paciente (A1).
    public function index(Request $request)
    {
        $paciente = $this->pacienteDeLaPagina($request);

        // Ver: recepción (gestionar o verificar) o el propio paciente (A1).
        $modo = $this->autorizar($request, $paciente, ["vinculos.gestionar", "vinculos.verificar"], true);

        // CU13 paso 2: activos primero; dentro de cada grupo, en el orden en que se registraron.
        $vinculos = $paciente->vinculos()
            ->with(["persona", "recepcionista.persona"])
            ->orderByRaw("CASE WHEN estado = 'ACTIVO' THEN 0 ELSE 1 END")
            ->orderBy("id")
            ->get();

        // CU13 paso 2 / RN16: un menor sin responsable legal ACTIVO y verificado no puede recibir citas.
        $esMenor = $paciente->esMenor();
        $verificados = $vinculos->filter(fn ($vinculo) => $vinculo->estado === "ACTIVO" && $vinculo->es_responsable_legal && $vinculo->fecha_verificacion !== null);

        return view("vinculos.index", [
            "paciente" => $paciente,
            "vinculos" => $vinculos,
            "modo" => $modo,
            "puedeGestionar" => $modo === "recepcion" && Gate::allows("vinculos.gestionar"),
            "puedeVerificar" => $modo === "recepcion" && $this->recepcionistaQueVerifica($request) !== null,
            "esMenor" => $esMenor,
            "sinResponsable" => $esMenor && $verificados->isEmpty(),
            // A2: id del único responsable verificado de un menor (su confirm lo advierte).
            "unicoResponsableId" => $esMenor && $verificados->count() === 1 ? $verificados->first()->id : null,
            "edadMayoria" => $this->edadMayoria(),
            "parentescos" => VinculoPaciente::PARENTESCOS,
        ]);
    }

    // CU13 pasos 3 a 7: registrar un vínculo (persona nueva o ya registrada).
    public function store(Request $request)
    {
        $request->validate(["paciente_id" => ["required", "integer", "exists:pacientes,id"]], $this->mensajes());
        $paciente = Paciente::with("persona")->findOrFail($request->input("paciente_id"));

        // Registrar: recepción con vinculos.gestionar o el propio paciente (A1).
        $modo = $this->autorizar($request, $paciente, ["vinculos.gestionar"], true);

        // CU13 paso 5: solo quien puede verificar marca «Verificado» (el paciente nunca, A1).
        $recepcionista = null;
        if ($request->boolean("verificado")) {
            $recepcionista = $modo === "recepcion" ? $this->recepcionistaQueVerifica($request) : null;
            abort_if($recepcionista === null, 403);
        }

        // CU13 paso 3: documento normalizado y búsqueda de la persona (Persona::buscarPorDocumento).
        $request->merge(Persona::normalizarEntrada($request->only(self::CAMPOS_DOCUMENTO)));
        $persona = $this->personaDelDocumento($request);

        // CU13 pasos 4 y 6: validar (si la persona es nueva, también sus datos).
        $validador = Validator::make($request->all(), $this->reglas($persona === null), $this->mensajes());
        $validador->after(fn ($validador) => $this->revisarVinculo($validador, $request, $paciente, $persona));
        $datos = $validador->validate();

        $tipos = [
            "parentesco" => $datos["parentesco"],
            "es_contacto_emergencia" => $request->boolean("es_contacto_emergencia"),
            "es_responsable_legal" => $request->boolean("es_responsable_legal"),
        ];
        // CU13 paso 7: la verificación guarda a la recepcionista y el momento, siempre juntos.
        $verificacion = $recepcionista ? ["recepcionista_verifica_id" => $recepcionista->id, "fecha_verificacion" => now()] : [];

        // CU13 paso 6: si la persona ya tiene un vínculo ACTIVO con este paciente, se actualiza ese.
        $existente = $persona
            ? $paciente->vinculos()->where("persona_id", $persona->id)->where("estado", "ACTIVO")->first()
            : null;

        try {
            // CU13 paso 7: persona (nueva o reutilizada) y vínculo en UNA transacción.
            DB::transaction(function () use ($existente, $persona, $paciente, $datos, $tipos, $verificacion, $modo, $request) {
                if ($existente) {
                    $anteriores = $this->foto($existente);

                    // A1: si el paciente cambia un vínculo verificado, vuelve a quedar pendiente.
                    $cambia = $existente->parentesco !== $tipos["parentesco"]
                        || $existente->es_contacto_emergencia !== $tipos["es_contacto_emergencia"]
                        || $existente->es_responsable_legal !== $tipos["es_responsable_legal"];
                    if ($modo === "paciente" && $cambia) {
                        $verificacion = ["recepcionista_verifica_id" => null, "fecha_verificacion" => null];
                    }

                    $existente->update($tipos + $verificacion);
                    Bitacora::registrar("MODIFICAR_VINCULO", "pacientes", $request->user()->id, true, "vinculos_paciente", $existente->id, "WEB", $anteriores, $this->foto($existente));

                    return;
                }

                $persona ??= Persona::create(Arr::only($datos, self::CAMPOS_PERSONA));

                $vinculo = VinculoPaciente::create(["paciente_id" => $paciente->id, "persona_id" => $persona->id, "estado" => "ACTIVO"] + $tipos + $verificacion);

                Bitacora::registrar("CREAR_VINCULO", "pacientes", $request->user()->id, true, "vinculos_paciente", $vinculo->id, "WEB", null, $this->foto($vinculo));
            });
        } catch (QueryException $e) {
            return $this->errorBase($e);
        }

        $respuesta = redirect()->route("vinculos-paciente.listar", ["paciente_id" => $paciente->id])
            ->with("exito", $existente ? "La persona ya estaba vinculada: se actualizó el vínculo" : "Vínculo registrado");

        // A1: lo que registra el paciente queda pendiente hasta que recepción revise el documento.
        return $modo === "paciente"
            ? $respuesta->with("aviso", "El vínculo queda pendiente de verificación: recepción revisará el documento de la persona.")
            : $respuesta;
    }

    // CU13 Modificar: parentesco y tipos (la persona no cambia). El id viaja oculto.
    public function update(Request $request)
    {
        $vinculo = $this->vinculoDelFormulario($request);
        $this->autorizar($request, $vinculo->paciente, ["vinculos.gestionar"], false);

        if ($vinculo->estado !== "ACTIVO") {
            return back()->withErrors(["operacion" => "Solo se puede modificar un vínculo activo."]);
        }

        // CU13 paso 6: mismas reglas del vínculo, con la persona ya registrada.
        $validador = Validator::make($request->all(), $this->reglas(false, false), $this->mensajes());
        $validador->after(fn ($validador) => $this->revisarVinculo($validador, $request, $vinculo->paciente, $vinculo->persona));
        $datos = $validador->validate();

        $anteriores = $this->foto($vinculo);

        try {
            DB::transaction(function () use ($vinculo, $datos, $anteriores, $request) {
                $vinculo->update([
                    "parentesco" => $datos["parentesco"],
                    "es_contacto_emergencia" => $request->boolean("es_contacto_emergencia"),
                    "es_responsable_legal" => $request->boolean("es_responsable_legal"),
                ]);

                Bitacora::registrar("MODIFICAR_VINCULO", "pacientes", $request->user()->id, true, "vinculos_paciente", $vinculo->id, "WEB", $anteriores, $this->foto($vinculo));
            });
        } catch (QueryException $e) {
            return $this->errorBase($e);
        }

        return redirect()->route("vinculos-paciente.listar", ["paciente_id" => $vinculo->paciente_id])->with("exito", "Vínculo actualizado");
    }

    // CU13 Verificar: la recepcionista revisó el documento físico. El id viaja oculto.
    public function verificar(Request $request)
    {
        $vinculo = $this->vinculoDelFormulario($request);

        // Solo con vinculos.verificar y perfil de recepcionista ACTIVO (A1: el paciente no verifica).
        abort_unless(Gate::allows("vinculos.verificar"), 403);
        $recepcionista = $this->recepcionistaQueVerifica($request);
        abort_if($recepcionista === null, 403);

        $volver = redirect()->route("vinculos-paciente.listar", ["paciente_id" => $vinculo->paciente_id]);

        if ($vinculo->estado !== "ACTIVO") {
            return $volver->withErrors(["operacion" => "Solo se puede verificar un vínculo activo."]);
        }
        if ($vinculo->fecha_verificacion !== null) {
            return $volver->with("aviso", "El vínculo ya estaba verificado.");
        }
        // CU13 paso 6: un responsable legal debe seguir siendo mayor de edad y tener documento.
        if ($vinculo->es_responsable_legal && ! $this->puedeSerResponsable($vinculo->persona)) {
            return $volver->withErrors(["operacion" => self::RESPONSABLE_INVALIDO]);
        }

        DB::transaction(function () use ($vinculo, $recepcionista, $request) {
            $anteriores = $this->foto($vinculo);
            $vinculo->update(["recepcionista_verifica_id" => $recepcionista->id, "fecha_verificacion" => now()]);

            Bitacora::registrar("VERIFICAR_VINCULO", "pacientes", $request->user()->id, true, "vinculos_paciente", $vinculo->id, "WEB", $anteriores, $this->foto($vinculo));
        });

        return $volver->with("exito", "Vínculo verificado");
    }

    // CU13 A2: desactivar (RN11: no se borra). El id viaja oculto en el formulario.
    public function delete(Request $request)
    {
        $vinculo = $this->vinculoDelFormulario($request);
        $paciente = $vinculo->paciente;
        $this->autorizar($request, $paciente, ["vinculos.gestionar"], false);

        $volver = redirect()->route("vinculos-paciente.listar", ["paciente_id" => $paciente->id]);

        if ($vinculo->estado !== "ACTIVO") {
            return $volver->with("aviso", "El vínculo ya estaba inactivo.");
        }

        $teniaResponsable = $paciente->tieneResponsableVerificado();

        DB::transaction(function () use ($vinculo, $request) {
            $vinculo->update(["estado" => "INACTIVO"]);

            Bitacora::registrar("DESACTIVAR_VINCULO", "pacientes", $request->user()->id, true, "vinculos_paciente", $vinculo->id, "WEB", ["estado" => "ACTIVO"], ["estado" => "INACTIVO"]);
        });

        // CU13 A2 / RN16: si era el único responsable legal verificado de un menor, se advierte.
        if ($paciente->esMenor() && $teniaResponsable && ! $paciente->tieneResponsableVerificado()) {
            return $volver->with("exito", "Vínculo desactivado. El paciente menor ya no tiene responsable legal verificado: no podrá recibir citas nuevas.");
        }

        return $volver->with("exito", "Vínculo desactivado");
    }

    // CU13 / CU15: autorización. Devuelve "recepcion" si el usuario tiene alguno de $permisos, o
    // "paciente" si es el propio paciente con perfil.gestionar y la acción se lo permite (A1). Si no, 403.
    private function autorizar(Request $request, Paciente $paciente, array $permisos, bool $permitePaciente): string
    {
        if (Gate::any($permisos)) {
            return "recepcion";
        }

        $esPropio = $request->user()->persona_id === $paciente->persona_id && Gate::allows("perfil.gestionar");
        abort_unless($permitePaciente && $esPropio, 403);

        return "paciente";
    }

    // CU13 paso 5: perfil de recepcionista ACTIVO del usuario con vinculos.verificar (null si no puede verificar).
    private function recepcionistaQueVerifica(Request $request): ?Recepcionista
    {
        if (! Gate::allows("vinculos.verificar")) {
            return null;
        }
        $recepcionista = $request->user()->persona?->recepcionista;

        return $recepcionista?->estado === "ACTIVO" ? $recepcionista : null;
    }

    // CU13 paso 1: el paciente de ?paciente_id=; sin él, el propio paciente del usuario («Mis contactos»).
    private function pacienteDeLaPagina(Request $request): Paciente
    {
        $id = $request->query("paciente_id");
        if ($id === null) {
            $propio = $request->user()->persona?->paciente;
            abort_if($propio === null, 404);

            return $propio->load("persona");
        }

        abort_unless(ctype_digit((string) $id), 404);

        return Paciente::with("persona")->findOrFail($id);
    }

    // El vínculo del id oculto (modificar, verificar y desactivar).
    private function vinculoDelFormulario(Request $request): VinculoPaciente
    {
        $request->validate(["id" => ["required", "integer", "exists:vinculos_paciente,id"]], $this->mensajes());

        return VinculoPaciente::with(["paciente.persona", "persona"])->findOrFail($request->input("id"));
    }

    // CU13 paso 3: persona ya registrada con el documento del formulario (null si no hay número o no existe).
    private function personaDelDocumento(Request $request): ?Persona
    {
        $tipo = $request->input("tipo_documento");
        $numero = $request->input("numero_documento");
        if (! is_string($numero) || $numero === "" || ! array_key_exists((string) $tipo, Persona::TIPOS_DOCUMENTO)) {
            return null;
        }

        $complemento = $request->input("complemento");

        return Persona::buscarPorDocumento($tipo, $numero, is_string($complemento) ? $complemento : null);
    }

    // CU13 paso 6: reglas que no se expresan con una sola regla de Laravel.
    // $persona: la persona ya registrada (null = nueva, se revisan los datos del formulario).
    private function revisarVinculo($validador, Request $request, Paciente $paciente, ?Persona $persona): void
    {
        $errores = $validador->errors();
        $esContacto = $request->boolean("es_contacto_emergencia");
        $esResponsable = $request->boolean("es_responsable_legal");

        // Al menos un tipo (ck_vinculos_tipo).
        if (! $esContacto && ! $esResponsable) {
            $errores->add("tipos", "Marque al menos un tipo de vínculo: contacto de emergencia o responsable legal.");
        }

        // No puede ser el mismo paciente.
        if ($persona !== null && $persona->id === $paciente->persona_id) {
            $errores->add("numero_documento", "La persona vinculada no puede ser el propio paciente");

            return;
        }

        // Responsable legal: mayor de edad y con documento con número.
        if ($esResponsable) {
            if ($persona !== null) {
                $valido = $this->puedeSerResponsable($persona);
            } else {
                $fecha = $request->input("fecha_nacimiento");
                $valido = ! empty($request->input("numero_documento"))
                    && ($errores->has("fecha_nacimiento") || Carbon::parse($fecha)->age >= $this->edadMayoria());
            }
            if (! $valido) {
                $errores->add("es_responsable_legal", self::RESPONSABLE_INVALIDO);
            }
        }

        // Contacto de emergencia: teléfono válido. Una persona nueva ya lo valida en Persona::reglas();
        // aquí se revisa el teléfono registrado de una persona que se reutiliza.
        if ($esContacto && $persona !== null && ! preg_match(Persona::FORMATO_TELEFONO, (string) $persona->telefono)) {
            $errores->add("es_contacto_emergencia", "El contacto de emergencia debe tener un teléfono válido registrado. Actualice sus datos en recepción.");
        }
    }

    // CU13 paso 6: mayor de edad (edad_mayoria) y con número de documento.
    private function puedeSerResponsable(Persona $persona): bool
    {
        return ! empty($persona->numero_documento) && $persona->edad >= $this->edadMayoria();
    }

    // Datos del vínculo para la bitácora (antes y después de un cambio).
    private function foto(VinculoPaciente $vinculo): array
    {
        $vinculo->refresh();

        return [
            "paciente_id" => $vinculo->paciente_id,
            "persona_id" => $vinculo->persona_id,
            "parentesco" => $vinculo->parentesco,
            "es_contacto_emergencia" => $vinculo->es_contacto_emergencia,
            "es_responsable_legal" => $vinculo->es_responsable_legal,
            "recepcionista_verifica_id" => $vinculo->recepcionista_verifica_id,
            "fecha_verificacion" => $vinculo->fecha_verificacion?->format("Y-m-d H:i:s"),
            "estado" => $vinculo->estado,
        ];
    }

    // CU13 paso 6: si la base rechaza el vínculo (23505 índice único, 23514 CHECK), se muestra un
    // mensaje en español. Cualquier otro error sigue su curso (página de error sin detalles).
    private function errorBase(QueryException $e)
    {
        $mensajes = [
            "ux_vinculos_paciente_persona_activo" => ["numero_documento", "La persona ya tiene un vínculo activo con este paciente."],
            "ux_personas_documento" => ["numero_documento", "Ya existe una persona con ese documento"],
            "ck_vinculos_tipo" => ["tipos", "Marque al menos un tipo de vínculo: contacto de emergencia o responsable legal."],
            "ck_vinculos_verificacion" => ["verificado", "La verificación debe registrar a la recepcionista y la fecha."],
        ];

        if (in_array($e->errorInfo[0] ?? null, ["23505", "23514"], true)) {
            foreach ($mensajes as $restriccion => [$campo, $mensaje]) {
                if (str_contains($e->getMessage(), $restriccion)) {
                    return back()->withErrors([$campo => $mensaje])->withInput();
                }
            }
        }

        throw $e;
    }

    // Edad de mayoría (configuraciones_sistema).
    private function edadMayoria(): int
    {
        return ConfiguracionSistema::entero("edad_mayoria");
    }

    // CU13 pasos 4 a 6. $personaNueva: se validan los datos de la persona (Persona::reglas()).
    // $conDocumento = false al modificar (la persona no cambia).
    private function reglas(bool $personaNueva, bool $conDocumento = true): array
    {
        $reglas = [
            "parentesco" => ["required", Rule::in(VinculoPaciente::PARENTESCOS)],
            "es_contacto_emergencia" => ["nullable", "boolean"],
            "es_responsable_legal" => ["nullable", "boolean"],
            "verificado" => ["nullable", "boolean"],
        ];

        if ($personaNueva) {
            return $reglas + Persona::reglas();
        }

        return $conDocumento ? $reglas + Arr::only(Persona::reglas(), self::CAMPOS_DOCUMENTO) : $reglas;
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó el vínculo.",
            "id.integer" => "El vínculo indicado no es válido.",
            "id.exists" => "El vínculo indicado no existe.",
            "paciente_id.required" => "No se indicó el paciente.",
            "paciente_id.integer" => "El paciente indicado no es válido.",
            "paciente_id.exists" => "El paciente indicado no existe.",
            "parentesco.required" => "Elija el parentesco.",
            "parentesco.in" => "El parentesco elegido no es válido.",
            "es_contacto_emergencia.boolean" => "El valor de Contacto de emergencia no es válido.",
            "es_responsable_legal.boolean" => "El valor de Responsable legal no es válido.",
            "verificado.boolean" => "El valor de Verificado no es válido.",
        ] + Persona::mensajes();
    }
}
