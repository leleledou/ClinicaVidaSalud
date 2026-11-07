<?php

namespace App\Services;

use App\Exceptions\AlergiaNoConfirmadaException;
use App\Exceptions\ReglaNegocioException;
use App\Models\Alergia;
use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Consulta;
use App\Models\Prescripcion;
use App\Models\Tratamiento;
use App\Models\Usuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio de CU31 Atender consulta médica (RF-32) y CU32 Gestionar tratamientos y
 * prescripciones (RF-33). Toda escritura va en UNA transacción con la consulta bloqueada (FOR UPDATE);
 * las reglas que no se cumplen lanzan ReglaNegocioException y la falta de permiso AuthorizationException.
 * Una consulta FINALIZADA no se modifica (RN10, trigger tg_consultas_finalizada_inmutable).
 */
class ConsultaService
{
    private const FINALIZADA = "La consulta ya está finalizada: no se puede modificar.";

    public function __construct(private CitaService $citas)
    {
    }

    /**
     * CU31 paso 2: inicia la consulta de una cita (o abre la existente: una cita tiene como máximo una).
     * PROGRAMADA o CONFIRMADA cuya hora ya llegó, o ATENDIDA por recepción (CU18) sin consulta.
     */
    public function iniciar(Cita $cita, Usuario $usuario): Consulta
    {
        return DB::transaction(function () use ($cita, $usuario) {
            // RN13: la cita queda bloqueada hasta el final de la transacción.
            $cita = Cita::whereKey($cita->id)->lockForUpdate()->firstOrFail();
            $this->autorizar($cita, $usuario);

            // CU31 A1: si ya tiene consulta (consultas.cita_id es único), se abre esa.
            if ($existente = $cita->consulta()->first()) {
                return $existente;
            }

            // CU31 A2: canceladas e inasistencias no se atienden; las vigentes, desde su hora de inicio.
            if (in_array($cita->estado, ["CANCELADA", "INASISTENCIA"], true)) {
                throw new ReglaNegocioException(($cita->estado === "CANCELADA" ? "La cita está cancelada" : "La cita quedó como inasistencia")
                    . ": no se puede iniciar una consulta.");
            }
            if (in_array($cita->estado, Cita::ESTADOS_VIGENTES, true) && ! $cita->comenzo()) {
                throw new ReglaNegocioException("La consulta se puede iniciar desde la hora de la cita (" . substr($cita->ficha->hora_inicio, 0, 5) . ").");
            }

            // CU31 paso 2: consulta EN_CURSO desde ahora.
            $consulta = Consulta::create(["cita_id" => $cita->id, "estado" => "EN_CURSO", "inicio" => now()]);

            Bitacora::registrar("INICIAR_CONSULTA", "clinica", $usuario->id, true, "consultas", $consulta->id, "WEB", null,
                ["cita" => $cita->codigo, "estado" => "EN_CURSO"]);

            return $consulta;
        });
    }

    /**
     * CU31 paso 5: guarda el avance de una consulta EN_CURSO. Devuelve los campos que cambiaron (la
     * bitácora MODIFICAR_CONSULTA solo los registra si hay alguno).
     */
    public function guardar(Consulta $consulta, array $datos, Usuario $usuario): array
    {
        return $this->enTransaccion(function () use ($consulta, $datos, $usuario) {
            $consulta = $this->bloquearEnCurso($consulta, $usuario);

            return $this->aplicar($consulta, $datos, $usuario);
        });
    }

    /**
     * CU31 paso 6: guarda lo que venga del formulario, exige CIE-10 y diagnóstico, FINALIZADA con fin =
     * ahora y, si la cita sigue PROGRAMADA o CONFIRMADA, la pasa a ATENDIDA (CitaService). Todo en UNA transacción.
     */
    public function finalizar(Consulta $consulta, array $datos, Usuario $usuario): Consulta
    {
        return $this->enTransaccion(function () use ($consulta, $datos, $usuario) {
            $consulta = $this->bloquearEnCurso($consulta, $usuario);
            $this->aplicar($consulta, $datos, $usuario);

            // CU31 paso 6 / A3: sin CIE-10 o sin diagnóstico no se finaliza.
            if (empty($consulta->codigo_cie10) || empty($consulta->diagnostico)) {
                throw new ReglaNegocioException("Para finalizar la consulta registre el código CIE-10 y el diagnóstico.");
            }

            $antes = $this->foto($consulta);
            $consulta->update(["estado" => "FINALIZADA", "fin" => now()]);

            // CU31 paso 6: «Al finalizar la consulta la cita queda atendida».
            $cita = $this->citas->atenderPorConsulta($consulta->cita, $usuario);

            Bitacora::registrar("FINALIZAR_CONSULTA", "clinica", $usuario->id, true, "consultas", $consulta->id, "WEB", $antes,
                $this->foto($consulta) + ["cita" => $cita->codigo, "estado_cita" => $cita->estado]);

            return $consulta;
        });
    }

    /**
     * CU32 paso 1: registra un tratamiento en una consulta EN_CURSO del propio médico. Inicio no anterior
     * al día de la consulta, fin opcional (>= inicio) y resultado de laboratorio opcional del MISMO paciente.
     */
    public function registrarTratamiento(Consulta $consulta, array $datos, Usuario $usuario): Tratamiento
    {
        return $this->enTransaccion(function () use ($consulta, $datos, $usuario) {
            $consulta = $this->bloquearEnCurso($consulta, $usuario, "tratamientos.gestionar");

            $inicio = Carbon::parse($datos["fecha_inicio"])->startOfDay();
            if ($inicio->lessThan($consulta->inicio->copy()->startOfDay())) {
                throw new ReglaNegocioException("La fecha de inicio no puede ser anterior al día de la consulta (" . $consulta->inicio->format("d/m/Y") . ").");
            }
            if (! empty($datos["fecha_fin"]) && Carbon::parse($datos["fecha_fin"])->lessThan($inicio)) {
                throw new ReglaNegocioException("La fecha de fin no puede ser anterior a la de inicio.");
            }
            // CU32 paso 1: el resultado que lo origina debe ser del mismo paciente.
            if (! empty($datos["resultado_laboratorio_id"])
                && ! $consulta->cita->paciente->resultadosLaboratorio()->where("resultados_laboratorio.id", $datos["resultado_laboratorio_id"])->exists()) {
                throw new ReglaNegocioException("El resultado de laboratorio elegido no es de este paciente.");
            }

            $tratamiento = Tratamiento::create([
                "consulta_id" => $consulta->id,
                "resultado_laboratorio_id" => $datos["resultado_laboratorio_id"] ?? null,
                "descripcion" => $datos["descripcion"],
                "fecha_inicio" => $inicio->toDateString(),
                "fecha_fin" => $datos["fecha_fin"] ?? null,
                "estado" => "EN_CURSO",
                "respuesta" => "PENDIENTE",
            ]);

            Bitacora::registrar("CREAR_TRATAMIENTO", "clinica", $usuario->id, true, "tratamientos", $tratamiento->id, "WEB", null, $this->fotoTratamiento($tratamiento));

            return $tratamiento;
        });
    }

    /**
     * CU32 pasos 2 y 3: agrega una prescripción a un tratamiento de una consulta EN_CURSO. Si el
     * medicamento coincide con una alergia ACTIVA del paciente y no viene la confirmación, no guarda y
     * lanza AlergiaNoConfirmadaException (se advierte, no se bloquea). Con la confirmación, guarda y
     * registra PRESCRIPCION_CON_ALERGIA_CONFIRMADA. Las prescripciones no se modifican ni se borran.
     */
    public function registrarPrescripcion(Tratamiento $tratamiento, array $datos, bool $confirmaAlergia, Usuario $usuario): Prescripcion
    {
        return $this->enTransaccion(function () use ($tratamiento, $datos, $confirmaAlergia, $usuario) {
            $consulta = $this->bloquearEnCurso($tratamiento->consulta, $usuario, "tratamientos.gestionar");

            // CU32 paso 3: advertencia de alergias.
            $alergias = $this->alergiasDelMedicamento($consulta, $datos["medicamento"]);
            if ($alergias->isNotEmpty() && ! $confirmaAlergia) {
                throw new AlergiaNoConfirmadaException($this->textoAlergias($alergias));
            }

            $prescripcion = Prescripcion::create(Arr::only($datos, ["medicamento", "dosis", "frecuencia", "duracion_dias", "via"]) + ["tratamiento_id" => $tratamiento->id]);

            Bitacora::registrar("CREAR_PRESCRIPCION", "clinica", $usuario->id, true, "prescripciones", $prescripcion->id, "WEB", null,
                $prescripcion->only(["tratamiento_id", "medicamento", "dosis", "frecuencia", "duracion_dias", "via"]));

            // CU32 paso 3: queda constancia de que el médico revisó la alergia y mantuvo la prescripción.
            foreach ($alergias as $alergia) {
                Bitacora::registrar("PRESCRIPCION_CON_ALERGIA_CONFIRMADA", "clinica", $usuario->id, true, "prescripciones", $prescripcion->id, "WEB", null,
                    ["medicamento" => $prescripcion->medicamento, "alergia" => $alergia->sustancia, "severidad" => $alergia->severidad]);
            }

            return $prescripcion;
        });
    }

    /**
     * CU32 paso 4: registra la evolución de un tratamiento (estado, respuesta, observación y fin). Cualquier
     * médico con tratamientos.gestionar y acceso clínico al paciente, aunque la consulta esté finalizada.
     * Devuelve los campos que cambiaron (vacío = «No hay cambios», sin bitácora).
     */
    public function registrarEvolucion(Tratamiento $tratamiento, array $datos, Usuario $usuario): array
    {
        return DB::transaction(function () use ($tratamiento, $datos, $usuario) {
            $tratamiento = Tratamiento::whereKey($tratamiento->id)->lockForUpdate()->firstOrFail();
            $paciente = $tratamiento->consulta->cita->paciente;
            if (! $usuario->tienePermiso("tratamientos.gestionar") || $paciente->accesoClinico($usuario) !== "medico") {
                throw new AuthorizationException("No puede registrar la evolución de este tratamiento.");
            }

            // CU32 paso 4: fin obligatorio al finalizar o suspender, y no anterior al inicio.
            $nuevos = [
                "estado" => $datos["estado"],
                "respuesta" => $datos["respuesta"],
                "observacion_evolucion" => $datos["observacion_evolucion"] ?? null,
                "fecha_fin" => $datos["fecha_fin"] ?? null,
            ];
            if ($nuevos["estado"] !== "EN_CURSO" && $nuevos["fecha_fin"] === null) {
                throw new ReglaNegocioException("Indique la fecha de fin del tratamiento finalizado o suspendido.");
            }
            if ($nuevos["fecha_fin"] !== null && $nuevos["fecha_fin"] < $tratamiento->fecha_inicio->toDateString()) {
                throw new ReglaNegocioException("La fecha de fin no puede ser anterior a la de inicio (" . $tratamiento->fecha_inicio->format("d/m/Y") . ").");
            }

            $anteriores = $this->fotoTratamiento($tratamiento);
            $cambiados = array_keys(array_filter($nuevos, fn ($valor, $campo) => $valor !== $anteriores[$campo], ARRAY_FILTER_USE_BOTH));
            if ($cambiados === []) {
                return [];
            }

            $tratamiento->update($nuevos);

            Bitacora::registrar("REGISTRAR_EVOLUCION_TRATAMIENTO", "clinica", $usuario->id, true, "tratamientos", $tratamiento->id, "WEB",
                Arr::only($anteriores, $cambiados), Arr::only($this->fotoTratamiento($tratamiento), $cambiados));

            return $cambiados;
        });
    }

    // CU32 paso 3: alergias ACTIVAS de tipo MEDICAMENTO que coinciden con el medicamento (Alergia::coincidentesConMedicamento).
    public function alergiasDelMedicamento(Consulta $consulta, string $medicamento)
    {
        $historiaId = $consulta->cita->paciente->historiaClinica?->id;

        return $historiaId ? Alergia::coincidentesConMedicamento($historiaId, $medicamento) : collect();
    }

    // CU32 paso 3: «El paciente tiene alergia activa a <sustancia> (<severidad>)».
    public function textoAlergias($alergias): string
    {
        return "El paciente tiene alergia activa a " . $alergias->map(fn ($alergia) => $alergia->sustancia . " (" . mb_strtolower($alergia->textoSeveridad()) . ")")->implode(" y a ");
    }

    // CU31 paso 1 / CU32 pasos 1-2: solo el médico de la cita (perfil ACTIVO) con consultas.gestionar
    // (y $permiso si se indica). Si no, AuthorizationException (403).
    private function autorizar(Cita $cita, Usuario $usuario, ?string $permiso = null): void
    {
        $medico = Consulta::medicoQueAtiende($usuario);
        if ($medico === null || $cita->medico()?->id !== $medico->id || ($permiso && ! $usuario->tienePermiso($permiso))) {
            throw new AuthorizationException("Solo el médico de la cita puede atender esta consulta.");
        }
    }

    // RN13: la consulta bloqueada (FOR UPDATE) y leída de nuevo, del médico de la cita y EN_CURSO.
    private function bloquearEnCurso(Consulta $consulta, Usuario $usuario, ?string $permiso = null): Consulta
    {
        $consulta = Consulta::whereKey($consulta->id)->lockForUpdate()->firstOrFail();
        $consulta->load(["cita.ficha.horario.medicoEspecialidad", "cita.paciente.historiaClinica"]);
        $this->autorizar($consulta->cita, $usuario, $permiso);

        if ($consulta->estado !== "EN_CURSO") {
            throw new ReglaNegocioException(self::FINALIZADA);
        }

        return $consulta;
    }

    // CU31 paso 5: guarda los campos clínicos que llegan (CIE-10 en mayúsculas) y registra solo los que cambiaron.
    private function aplicar(Consulta $consulta, array $datos, Usuario $usuario): array
    {
        $nuevos = Arr::only($datos, Consulta::CAMPOS_CLINICOS);
        if (isset($nuevos["codigo_cie10"])) {
            $nuevos["codigo_cie10"] = mb_strtoupper($nuevos["codigo_cie10"]);
        }

        $anteriores = $this->foto($consulta);
        $consulta->fill($nuevos);
        $cambiados = array_keys($consulta->getDirty());
        if ($cambiados === []) {
            return [];
        }

        $consulta->save();

        Bitacora::registrar("MODIFICAR_CONSULTA", "clinica", $usuario->id, true, "consultas", $consulta->id, "WEB",
            Arr::only($anteriores, $cambiados), Arr::only($this->foto($consulta), $cambiados));

        return $cambiados;
    }

    // RN10: si la base rechaza el cambio de una consulta FINALIZADA (otro usuario la finalizó en el mismo
    // instante), se muestra un mensaje claro en lugar del error SQL.
    private function enTransaccion(\Closure $operacion)
    {
        try {
            return DB::transaction($operacion);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), "FINALIZADA y no puede modificarse")) {
                throw new ReglaNegocioException(self::FINALIZADA);
            }

            throw $e;
        }
    }

    // Datos de la consulta para la bitácora.
    private function foto(Consulta $consulta): array
    {
        return $consulta->only(array_merge(Consulta::CAMPOS_CLINICOS, ["estado"])) + [
            "fin" => $consulta->fin?->format("Y-m-d H:i:s"),
        ];
    }

    // Datos del tratamiento para la bitácora.
    private function fotoTratamiento(Tratamiento $tratamiento): array
    {
        return [
            "consulta_id" => $tratamiento->consulta_id,
            "resultado_laboratorio_id" => $tratamiento->resultado_laboratorio_id,
            "descripcion" => $tratamiento->descripcion,
            "fecha_inicio" => $tratamiento->fecha_inicio?->toDateString(),
            "fecha_fin" => $tratamiento->fecha_fin?->toDateString(),
            "estado" => $tratamiento->estado,
            "respuesta" => $tratamiento->respuesta,
            "observacion_evolucion" => $tratamiento->observacion_evolucion,
        ];
    }
}
