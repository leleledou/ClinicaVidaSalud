<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use App\Models\Bitacora;
use App\Models\Paciente;
use App\Models\PacienteSeguro;
use App\Models\Recepcionista;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * CU24 Gestionar seguros del paciente (RF-18, RN20). Recepción: seguros.gestionar (registrar,
 * modificar, desactivar, reactivar) y seguros.verificar (verificar, solo con perfil de recepcionista
 * ACTIVO). El paciente no entra a este CU. No hay borrado físico (RN11): desactivar cambia el estado
 * a INACTIVO.
 */
class PacienteSeguroController extends Controller
{
    // Datos de la póliza que se guardan desde el formulario.
    private const CAMPOS = ["aseguradora_id", "numero_poliza", "plan", "titular", "vigente_desde", "vigente_hasta"];

    // CU24 A1: si cambia alguno de estos datos, la verificación se vuelve a fijar (el plan no cuenta).
    private const CAMPOS_VERIFICADOS = ["aseguradora_id", "numero_poliza", "titular", "vigente_desde", "vigente_hasta"];

    private const POLIZA_REPETIDA = "El paciente ya tiene una póliza activa con esa aseguradora y ese número";

    // CU24 pasos 1-2: datos del paciente de ?paciente_id= y sus pólizas (activas primero).
    public function index(Request $request)
    {
        $paciente = $this->pacienteDeLaPagina($request);
        $this->autorizar(["seguros.gestionar", "seguros.verificar"]);

        $seguros = $paciente->seguros()
            ->with(["aseguradora", "recepcionista.persona"])
            ->activasPrimero()
            ->get();

        return view("seguros.index", [
            "paciente" => $paciente,
            "seguros" => $seguros,
            "puedeGestionar" => Gate::allows("seguros.gestionar"),
            "puedeVerificar" => $this->recepcionistaQueVerifica($request) !== null,
            // CU24 paso 3: solo aseguradoras ACTIVAS (convenio vigente).
            "aseguradoras" => Aseguradora::where("estado", "ACTIVO")->orderBy("nombre")->get(["id", "nombre"]),
        ]);
    }

    // CU24 pasos 3 a 6: registrar una póliza del paciente.
    public function store(Request $request)
    {
        $request->validate(["paciente_id" => ["required", "integer", "exists:pacientes,id"]], $this->mensajes());
        $paciente = Paciente::findOrFail($request->input("paciente_id"));
        $this->autorizar(["seguros.gestionar"]);

        // CU24 paso 4: limpiar y validar (incluye la póliza ACTIVA repetida).
        $this->limpiarEntrada($request);
        $datos = $this->validar($request, $paciente->id);

        // CU24 paso 5: queda verificada si quien registra puede verificar (como CU13); si no, Pendiente.
        $recepcionista = $this->recepcionistaQueVerifica($request);

        // CU24 paso 6: guardar ACTIVA y registrar en la bitácora.
        DB::transaction(function () use ($paciente, $datos, $recepcionista, $request) {
            $seguro = PacienteSeguro::create($datos + ["paciente_id" => $paciente->id, "estado" => "ACTIVO"] + $this->verificacion($recepcionista));

            Bitacora::registrar("CREAR_SEGURO", "pacientes", $request->user()->id, true, "seguros_paciente", $seguro->id, "WEB", null, $this->foto($seguro));
        });

        return $this->volver($paciente->id)->with("exito", $recepcionista ? "Póliza registrada y verificada" : "Póliza registrada: queda pendiente de verificación");
    }

    // CU24 A1: modificar una póliza ACTIVA. El id viaja oculto en el formulario.
    public function update(Request $request)
    {
        $seguro = $this->seguroDelFormulario($request);
        $this->autorizar(["seguros.gestionar"]);

        if ($seguro->estado !== "ACTIVO") {
            return $this->volver($seguro->paciente_id)->withErrors(["operacion" => "Solo se puede modificar una póliza activa."]);
        }

        // CU24 A1: las mismas validaciones que al registrar, sin contarse a sí misma.
        $this->limpiarEntrada($request);
        $datos = $this->validar($request, $seguro->paciente_id, $seguro->id);

        $anteriores = $this->foto($seguro);
        $vigenciaAnterior = [$seguro->vigente_desde->toDateString(), $seguro->vigente_hasta->toDateString()];

        // CU24 A1: si cambia aseguradora, número, titular o vigencia, la verificación se vuelve a fijar con
        // quien modifica (si puede verificar) o vuelve a Pendiente.
        $seguro->fill($datos);
        $recepcionista = $this->recepcionistaQueVerifica($request);
        if ($seguro->isDirty(self::CAMPOS_VERIFICADOS)) {
            $seguro->fill($this->verificacion($recepcionista, true));
        }
        $cambioVigencia = [$seguro->vigente_desde->toDateString(), $seguro->vigente_hasta->toDateString()] !== $vigenciaAnterior;
        $verificacionReiniciada = $seguro->isDirty("fecha_verificacion") || $seguro->isDirty("recepcionista_verifica_id");

        DB::transaction(function () use ($seguro, $anteriores, $request) {
            $seguro->save();

            Bitacora::registrar("MODIFICAR_SEGURO", "pacientes", $request->user()->id, true, "seguros_paciente", $seguro->id, "WEB", $anteriores, $this->foto($seguro));
        });

        $mensaje = "Póliza actualizada";
        if ($verificacionReiniciada) {
            $mensaje .= $recepcionista ? " y verificada de nuevo" : ": queda pendiente de verificación";
        }

        // CU24 paso 7: citas futuras que quedan fuera de la nueva vigencia (no cambian, RN20).
        if ($cambioVigencia && ($fuera = $seguro->citasFuturas($seguro->vigente_desde->toDateString(), $seguro->vigente_hasta->toDateString())) > 0) {
            $mensaje .= ". {$fuera} cita(s) programada(s) con esta póliza quedan fuera de la nueva vigencia: esas citas no cambian (se validaron al programarlas, RN20)";
        }

        return $this->volver($seguro->paciente_id)->with("exito", $mensaje);
    }

    // CU24 A2: verificar una póliza ACTIVA y Pendiente (recepcionista y fecha). El id viaja oculto.
    public function verificar(Request $request)
    {
        $seguro = $this->seguroDelFormulario($request);

        // Solo con seguros.verificar y perfil de recepcionista ACTIVO.
        $recepcionista = $this->recepcionistaQueVerifica($request);
        abort_if($recepcionista === null, 403);

        $volver = $this->volver($seguro->paciente_id);

        if ($seguro->estado !== "ACTIVO") {
            return $volver->withErrors(["operacion" => "Solo se puede verificar una póliza activa."]);
        }
        if ($seguro->verificada()) {
            return $volver->with("aviso", "La póliza ya estaba verificada.");
        }

        DB::transaction(function () use ($seguro, $recepcionista, $request) {
            $anteriores = $this->foto($seguro);
            $seguro->update($this->verificacion($recepcionista));

            Bitacora::registrar("VERIFICAR_SEGURO", "pacientes", $request->user()->id, true, "seguros_paciente", $seguro->id, "WEB", $anteriores, $this->foto($seguro));
        });

        return $volver->with("exito", "Póliza verificada");
    }

    // CU24 A3: desactivar (RN11: no se borra). El id viaja oculto en el formulario.
    public function delete(Request $request)
    {
        $seguro = $this->seguroDelFormulario($request);
        $this->autorizar(["seguros.gestionar"]);

        $volver = $this->volver($seguro->paciente_id);

        if ($seguro->estado !== "ACTIVO") {
            return $volver->with("aviso", "La póliza ya estaba inactiva.");
        }

        // CU24 paso 7: se permite aunque tenga citas futuras; esas citas no cambian (RN20).
        $futuras = $seguro->citasFuturas();

        $this->cambiarEstado($seguro, "INACTIVO", "DESACTIVAR_SEGURO", $request);

        return $volver->with("exito", "Póliza desactivada" . ($futuras > 0
            ? ". {$futuras} cita(s) programada(s) con esta póliza no cambian (se validaron al programarlas, RN20)"
            : ""));
    }

    // CU24 A4: reactivar una póliza inactiva (con la misma regla de póliza repetida). El id viaja oculto.
    public function activar(Request $request)
    {
        $seguro = $this->seguroDelFormulario($request);
        $this->autorizar(["seguros.gestionar"]);

        $volver = $this->volver($seguro->paciente_id);

        if ($seguro->estado === "ACTIVO") {
            return $volver->with("aviso", "La póliza ya estaba activa.");
        }
        if (PacienteSeguro::hayDuplicadoActivo($seguro->paciente_id, $seguro->aseguradora_id, $seguro->numero_poliza, $seguro->id)) {
            return $volver->withErrors(["operacion" => self::POLIZA_REPETIDA . ": desactive esa otra antes de reactivar esta."]);
        }

        $this->cambiarEstado($seguro, "ACTIVO", "ACTIVAR_SEGURO", $request);

        return $volver->with("exito", "Póliza activada");
    }

    // CU24 A3 / A4: cambia el estado y lo registra en la bitácora.
    private function cambiarEstado(PacienteSeguro $seguro, string $estado, string $accion, Request $request): void
    {
        DB::transaction(function () use ($seguro, $estado, $accion, $request) {
            $anteriores = $this->foto($seguro);
            $seguro->update(["estado" => $estado]);

            Bitacora::registrar($accion, "pacientes", $request->user()->id, true, "seguros_paciente", $seguro->id, "WEB", $anteriores, $this->foto($seguro));
        });
    }

    // CU24: autorización de recepción (el paciente no entra a este CU). Sin ninguno de $permisos, 403.
    private function autorizar(array $permisos): void
    {
        abort_unless(Gate::any($permisos), 403);
    }

    // CU24 paso 5 / A2: perfil de recepcionista ACTIVO del usuario con seguros.verificar (null si no puede verificar).
    private function recepcionistaQueVerifica(Request $request): ?Recepcionista
    {
        if (! Gate::allows("seguros.verificar")) {
            return null;
        }
        $recepcionista = $request->user()->persona?->recepcionista;

        return $recepcionista?->estado === "ACTIVO" ? $recepcionista : null;
    }

    // CU24 paso 5 / A1 / A2: datos de la verificación, siempre juntos (ck_seguros_verificacion). Sin
    // recepcionista: nada al registrar, o Pendiente ($pendiente) al modificar.
    private function verificacion(?Recepcionista $recepcionista, bool $pendiente = false): array
    {
        if ($recepcionista) {
            return ["recepcionista_verifica_id" => $recepcionista->id, "fecha_verificacion" => now()];
        }

        return $pendiente ? ["recepcionista_verifica_id" => null, "fecha_verificacion" => null] : [];
    }

    // CU24 paso 1: el paciente de ?paciente_id= (obligatorio).
    private function pacienteDeLaPagina(Request $request): Paciente
    {
        $id = $request->query("paciente_id");
        abort_unless(ctype_digit((string) $id), 404);

        return Paciente::with("persona")->findOrFail($id);
    }

    // La póliza del id oculto (modificar, verificar, desactivar y activar).
    private function seguroDelFormulario(Request $request): PacienteSeguro
    {
        $request->validate($this->reglaId(), $this->mensajes());

        return PacienteSeguro::findOrFail($request->input("id"));
    }

    // Vuelve a la página de seguros del paciente.
    private function volver(int $pacienteId)
    {
        return redirect()->route("seguros-paciente.listar", ["paciente_id" => $pacienteId]);
    }

    // CU24 paso 4: número, plan y titular sin espacios en los extremos (el plan vacío queda null).
    private function limpiarEntrada(Request $request): void
    {
        foreach (["numero_poliza", "plan", "titular"] as $campo) {
            if (is_string($request->input($campo))) {
                $request->merge([$campo => trim($request->input($campo))]);
            }
        }
        if ($request->input("plan") === "") {
            $request->merge(["plan" => null]);
        }
    }

    // CU24 paso 4: reglas() y la póliza ACTIVA repetida del mismo paciente ($idIgnorar: la propia póliza).
    private function validar(Request $request, int $pacienteId, ?int $idIgnorar = null): array
    {
        $validador = Validator::make($request->all(), $this->reglas(), $this->mensajes());
        $validador->after(function ($validador) use ($request, $pacienteId, $idIgnorar) {
            if ($validador->errors()->hasAny(["aseguradora_id", "numero_poliza"])) {
                return;
            }
            if (PacienteSeguro::hayDuplicadoActivo($pacienteId, (int) $request->input("aseguradora_id"), $request->input("numero_poliza"), $idIgnorar)) {
                $validador->errors()->add("numero_poliza", self::POLIZA_REPETIDA);
            }
        });

        return $validador->validate();
    }

    // Datos de la póliza para la bitácora (antes y después de un cambio).
    private function foto(PacienteSeguro $seguro): array
    {
        $seguro->refresh();

        return [
            "paciente_id" => $seguro->paciente_id,
            "aseguradora_id" => $seguro->aseguradora_id,
            "numero_poliza" => $seguro->numero_poliza,
            "plan" => $seguro->plan,
            "titular" => $seguro->titular,
            "vigente_desde" => $seguro->vigente_desde->toDateString(),
            "vigente_hasta" => $seguro->vigente_hasta->toDateString(),
            "recepcionista_verifica_id" => $seguro->recepcionista_verifica_id,
            "fecha_verificacion" => $seguro->fecha_verificacion?->format("Y-m-d H:i:s"),
            "estado" => $seguro->estado,
        ];
    }

    // Regla del id que llega oculto en el formulario.
    private function reglaId(): array
    {
        return ["id" => ["required", "integer", "exists:seguros_paciente,id"]];
    }

    // CU24 paso 4: aseguradora ACTIVA, número y titular obligatorios, plan opcional y vigencia
    // con hasta >= desde (ck_seguros_vigencia).
    private function reglas(): array
    {
        return [
            "aseguradora_id" => ["required", "integer", Rule::exists("aseguradoras", "id")->where("estado", "ACTIVO")],
            "numero_poliza" => ["required", "string", "max:255"],
            "plan" => ["nullable", "string", "max:255"],
            "titular" => ["required", "string", "max:255"],
            "vigente_desde" => ["required", "date_format:Y-m-d"],
            "vigente_hasta" => ["bail", "required", "date_format:Y-m-d", "after_or_equal:vigente_desde"],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return [
            "id.required" => "No se indicó la póliza.",
            "id.integer" => "La póliza indicada no es válida.",
            "id.exists" => "La póliza indicada no existe.",
            "paciente_id.required" => "No se indicó el paciente.",
            "paciente_id.integer" => "El paciente indicado no es válido.",
            "paciente_id.exists" => "El paciente indicado no existe.",
            "aseguradora_id.required" => "Elija la aseguradora.",
            "aseguradora_id.integer" => "La aseguradora elegida no es válida.",
            "aseguradora_id.exists" => "La aseguradora elegida no existe o está inactiva.",
            "numero_poliza.required" => "El campo Número de póliza es obligatorio.",
            "numero_poliza.max" => "El número de póliza no puede superar los 255 caracteres.",
            "plan.max" => "El plan no puede superar los 255 caracteres.",
            "titular.required" => "El campo Titular es obligatorio.",
            "titular.max" => "El titular no puede superar los 255 caracteres.",
            "vigente_desde.required" => "El campo Vigente desde es obligatorio.",
            "vigente_desde.date_format" => "La fecha Vigente desde no es válida.",
            "vigente_hasta.required" => "El campo Vigente hasta es obligatorio.",
            "vigente_hasta.date_format" => "La fecha Vigente hasta no es válida.",
            "vigente_hasta.after_or_equal" => "La fecha Vigente hasta no puede ser anterior a Vigente desde.",
        ];
    }
}
