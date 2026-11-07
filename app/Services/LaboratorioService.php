<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\Bitacora;
use App\Models\Consulta;
use App\Models\OrdenLaboratorio;
use App\Models\TipoExamen;
use App\Models\Usuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio del laboratorio: CU34 Solicitar orden de laboratorio (RF-35) y, en el Bloque 6B,
 * la carga y revisión de resultados. Toda escritura va en UNA transacción con el registro bloqueado
 * (FOR UPDATE); las reglas que no se cumplen lanzan ReglaNegocioException y la falta de permiso
 * AuthorizationException. Las órdenes no se eliminan (RN11, trigger sin DELETE): se anulan.
 */
class LaboratorioService
{
    /**
     * CU34 paso 2: el médico de la consulta EN_CURSO solicita un examen ACTIVO con parámetros. No se
     * repite un examen no ANULADO en la misma consulta. Código LAB-<cita>-<n>, estado SOLICITADA.
     */
    public function solicitar(Consulta $consulta, array $datos, Usuario $usuario): OrdenLaboratorio
    {
        return DB::transaction(function () use ($consulta, $datos, $usuario) {
            // RN13: la consulta bloqueada y leída de nuevo.
            $consulta = Consulta::whereKey($consulta->id)->lockForUpdate()->firstOrFail();
            $consulta->load("cita.ficha.horario.medicoEspecialidad");
            $this->autorizar($consulta, $usuario);

            // CU34 A1: solo durante la consulta.
            if ($consulta->estado !== "EN_CURSO") {
                throw new ReglaNegocioException("La consulta ya está finalizada: no se pueden solicitar exámenes.");
            }

            // CU34 A2: examen ACTIVO y con al menos un parámetro.
            $examen = TipoExamen::solicitables()->whereKey($datos["tipo_examen_id"])->first();
            if ($examen === null) {
                throw new ReglaNegocioException("El examen elegido no está disponible (inactivo o sin parámetros).");
            }

            // CU34 A3: no dos órdenes no anuladas del mismo examen en la misma consulta.
            if (OrdenLaboratorio::where("consulta_id", $consulta->id)->where("tipo_examen_id", $examen->id)->where("estado", "<>", "ANULADA")->exists()) {
                throw new ReglaNegocioException("Ya se solicitó {$examen->nombre} en esta consulta.");
            }

            $orden = OrdenLaboratorio::create([
                "consulta_id" => $consulta->id,
                "tipo_examen_id" => $examen->id,
                "codigo" => OrdenLaboratorio::siguienteCodigo($consulta->cita),
                "fecha_solicitud" => now(),
                "prioridad" => $datos["prioridad"],
                "estado" => "SOLICITADA",
            ]);

            Bitacora::registrar("SOLICITAR_EXAMEN", "laboratorio", $usuario->id, true, "ordenes_laboratorio", $orden->id, "WEB", null, $this->foto($orden, $examen));

            return $orden;
        });
    }

    /**
     * CU34 paso 3: el médico que la pidió anula una orden SOLICITADA (sin muestra tomada). Se puede
     * aunque la consulta ya esté finalizada: la orden es otra tabla.
     */
    public function anular(OrdenLaboratorio $orden, Usuario $usuario): OrdenLaboratorio
    {
        return DB::transaction(function () use ($orden, $usuario) {
            $orden = OrdenLaboratorio::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            $orden->load(["consulta.cita.ficha.horario.medicoEspecialidad", "tipoExamen"]);
            $this->autorizar($orden->consulta, $usuario);

            // CU34 A4: con la muestra tomada (o después) ya no se anula.
            if ($orden->estado !== "SOLICITADA") {
                throw new ReglaNegocioException("Solo se anula una orden solicitada (sin muestra tomada). La orden {$orden->codigo} está "
                    . mb_strtolower($orden->textoEstado()) . ".");
            }

            $antes = $this->foto($orden, $orden->tipoExamen);
            $orden->update(["estado" => "ANULADA"]);

            Bitacora::registrar("ANULAR_ORDEN", "laboratorio", $usuario->id, true, "ordenes_laboratorio", $orden->id, "WEB", $antes, $this->foto($orden, $orden->tipoExamen));

            return $orden;
        });
    }

    // CU34 paso 1: solo el médico de la cita de la consulta (perfil ACTIVO) con consultas.gestionar. Si no, 403.
    private function autorizar(Consulta $consulta, Usuario $usuario): void
    {
        $medico = Consulta::medicoQueAtiende($usuario);
        if ($medico === null || $consulta->cita->medico()?->id !== $medico->id) {
            throw new AuthorizationException("Solo el médico de la consulta puede solicitar o anular sus órdenes.");
        }
    }

    // Datos de la orden para la bitácora.
    private function foto(OrdenLaboratorio $orden, TipoExamen $examen): array
    {
        return [
            "codigo" => $orden->codigo,
            "consulta_id" => $orden->consulta_id,
            "examen" => $examen->codigo,
            "prioridad" => $orden->prioridad,
            "estado" => $orden->estado,
        ];
    }
}
