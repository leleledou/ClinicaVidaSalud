<?php

namespace App\Http\Controllers;

use App\Models\Cita;
use App\Models\Medico;
use App\Models\Paciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * CU01 paso 8: panel de inicio después de iniciar sesión.
 */
class PanelController extends Controller
{
    // Saludo con los nombres de la persona y su rol, y tarjetas de resumen según los permisos (solo lectura).
    public function index(Request $request)
    {
        $usuario = $request->user()->load(["persona", "rol"]);

        // CU12: un paciente con el correo sin verificar no puede reservar citas en línea.
        $correoPendiente = $usuario->rol->nombre === "PACIENTE" && $usuario->correo_verificado_en === null;

        $hoy = now()->toDateString();
        // Citas cuya ficha cumple la condición (citas → fichas → horarios → medicos_especialidades).
        $citas = fn () => Cita::join("fichas", "fichas.id", "=", "citas.ficha_id")
            ->join("horarios_atencion", "horarios_atencion.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id");

        $resumen = [];
        if (Gate::any(["citas.programar", "agenda.consultar"])) {
            $resumen["Citas de hoy"] = $citas()->where("fichas.fecha", $hoy)->where("citas.estado", "<>", "CANCELADA")->count();
            $resumen["Citas pendientes"] = $citas()->where("fichas.fecha", ">=", $hoy)->whereIn("citas.estado", Cita::ESTADOS_VIGENTES)->count();
            $resumen["Pacientes registrados"] = Paciente::where("estado", "ACTIVO")->count();
            $resumen["Médicos activos"] = Medico::where("estado", "ACTIVO")->count();
        } elseif (Gate::allows("agenda.propia") && $usuario->persona?->medico) {
            $medicoId = $usuario->persona->medico->id;
            $resumen["Mis citas de hoy"] = $citas()->where("medicos_especialidades.medico_id", $medicoId)
                ->where("fichas.fecha", $hoy)->where("citas.estado", "<>", "CANCELADA")->count();
            $resumen["Mis citas pendientes"] = $citas()->where("medicos_especialidades.medico_id", $medicoId)
                ->where("fichas.fecha", ">=", $hoy)->whereIn("citas.estado", Cita::ESTADOS_VIGENTES)->count();
        } elseif (Gate::allows("citas.historial_propio")) {
            // CU20 A1: las propias y las de los menores a su cargo.
            $pacientes = Paciente::aCargoDe($usuario->persona)->pluck("id");
            $resumen["Mis citas pendientes"] = $citas()->whereIn("citas.paciente_id", $pacientes)
                ->where("fichas.fecha", ">=", $hoy)->whereIn("citas.estado", Cita::ESTADOS_VIGENTES)->count();
        }

        return view("panel", compact("usuario", "correoPendiente", "resumen"));
    }
}
