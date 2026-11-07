<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Paciente;
use App\Models\Persona;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CU28 Gestionar perfil del paciente (RF-19). Requiere el permiso perfil.gestionar y perfil de
 * paciente; solo su propio perfil (no recibe id). Edita sus datos de contacto (Persona) y sus
 * preferencias de notificación (Usuario). Los datos personales y el documento solo los corrige
 * recepción (CU10). No hay borrado (RN11): solo se modifican datos.
 */
class PerfilPacienteController extends Controller
{
    // Datos de contacto de la persona que el paciente puede editar.
    private const CAMPOS_PERSONA = ["telefono", "correo", "direccion"];

    // Preferencias de notificación de la cuenta.
    private const CAMPOS_USUARIO = ["notificar_por_correo", "notificar_por_push", "horas_anticipacion"];

    // CU28 pasos 1-2: datos personales (solo lectura), contacto y preferencias.
    public function show(Request $request)
    {
        $paciente = $this->pacienteDelUsuario($request);

        return view("perfil.show", [
            "paciente" => $paciente,
            "usuario" => $request->user(),
            "horas" => $request->user()->opcionesHorasAnticipacion(),
        ]);
    }

    // CU28 pasos 3 a 6: guardar el contacto y las preferencias.
    public function update(Request $request)
    {
        $paciente = $this->pacienteDelUsuario($request);
        $persona = $paciente->persona;
        $usuario = $request->user();

        // CU28 paso 4: limpiar y validar (reglas y mensajes de Persona para el contacto).
        $this->limpiarEntrada($request);
        $datos = $request->validate($this->reglas($usuario), $this->mensajes());

        $nuevos = [
            "telefono" => $datos["telefono"],
            "correo" => $datos["correo"] ?? null,
            "direccion" => $datos["direccion"] ?? null,
            "notificar_por_correo" => $request->boolean("notificar_por_correo"),
            "notificar_por_push" => $request->boolean("notificar_por_push"),
            "horas_anticipacion" => (int) $datos["horas_anticipacion"],
        ];
        $anteriores = $this->foto($persona, $usuario);
        $cambiados = array_keys(array_filter($nuevos, fn ($valor, $campo) => $valor !== $anteriores[$campo], ARRAY_FILTER_USE_BOTH));

        // CU28 A1: si nada cambió, no se guarda ni se registra.
        if ($cambiados === []) {
            return redirect()->route("perfil.mostrar")->with("aviso", "No hay cambios");
        }

        // CU28 paso 5: Persona y Usuario en UNA transacción; la bitácora guarda solo lo que cambió.
        DB::transaction(function () use ($persona, $usuario, $paciente, $nuevos, $anteriores, $cambiados) {
            $persona->update(Arr::only($nuevos, self::CAMPOS_PERSONA));
            $usuario->update(Arr::only($nuevos, self::CAMPOS_USUARIO));

            Bitacora::registrar("MODIFICAR_PERFIL", "pacientes", $usuario->id, true, "pacientes", $paciente->id, "WEB",
                Arr::only($anteriores, $cambiados), Arr::only($nuevos, $cambiados));
        });

        // CU28 paso 6: los recordatorios ya PENDIENTES no se recalculan.
        $respuesta = redirect()->route("perfil.mostrar")->with("exito", "Perfil actualizado");

        return in_array("horas_anticipacion", $cambiados, true)
            ? $respuesta->with("aviso", "Los recordatorios ya programados no cambian: las {$nuevos['horas_anticipacion']} horas de anticipación se aplican a las citas que se programen o reprogramen desde ahora.")
            : $respuesta;
    }

    // CU28 paso 1: el perfil de paciente del usuario (sin perfil de paciente, 404).
    private function pacienteDelUsuario(Request $request): Paciente
    {
        $paciente = $request->user()->persona?->paciente;
        abort_if($paciente === null, 404);

        return $paciente->load("persona");
    }

    // CU28 paso 4: contacto sin espacios en los extremos (correo y dirección vacíos quedan null).
    private function limpiarEntrada(Request $request): void
    {
        foreach (self::CAMPOS_PERSONA as $campo) {
            if (is_string($request->input($campo))) {
                $valor = trim($request->input($campo));
                $request->merge([$campo => $valor === "" && $campo !== "telefono" ? null : $valor]);
            }
        }
    }

    // Datos del perfil para comparar y para la bitácora.
    private function foto(Persona $persona, Usuario $usuario): array
    {
        return [
            "telefono" => $persona->telefono,
            "correo" => $persona->correo,
            "direccion" => $persona->direccion,
            "notificar_por_correo" => (bool) $usuario->notificar_por_correo,
            "notificar_por_push" => (bool) $usuario->notificar_por_push,
            "horas_anticipacion" => (int) $usuario->horas_anticipacion,
        ];
    }

    // CU28 paso 4: teléfono obligatorio (Persona::FORMATO_TELEFONO), correo de contacto y dirección
    // opcionales (reglas de Persona); preferencias con horas de la lista (ck_usuarios_horas_anticipacion > 0).
    private function reglas(Usuario $usuario): array
    {
        return Arr::only(Persona::reglas(), self::CAMPOS_PERSONA) + [
            "notificar_por_correo" => ["nullable", "boolean"],
            "notificar_por_push" => ["nullable", "boolean"],
            "horas_anticipacion" => ["required", "integer", Rule::in($usuario->opcionesHorasAnticipacion())],
        ];
    }

    // Mensajes en español (los mismos que muestra la validación en JavaScript).
    private function mensajes(): array
    {
        return Arr::only(Persona::mensajes(), ["telefono.required", "telefono.regex", "correo.email", "correo.max", "direccion.max"]) + [
            "notificar_por_correo.boolean" => "El valor de Avisos por correo no es válido.",
            "notificar_por_push.boolean" => "El valor de Avisos push no es válido.",
            "horas_anticipacion.required" => "Elija con cuántas horas de anticipación recibir el recordatorio.",
            "horas_anticipacion.integer" => "Las horas de anticipación no son válidas.",
            "horas_anticipacion.in" => "Elija una de las horas de anticipación de la lista.",
        ];
    }
}
