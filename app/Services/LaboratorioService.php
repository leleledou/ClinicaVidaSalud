<?php

namespace App\Services;

use App\Exceptions\ReglaNegocioException;
use App\Models\Bitacora;
use App\Models\Consulta;
use App\Models\Notificacion;
use App\Models\OrdenLaboratorio;
use App\Models\Persona;
use App\Models\RangoReferencia;
use App\Models\ResultadoLaboratorio;
use App\Models\TipoExamen;
use App\Models\Usuario;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de negocio del laboratorio: CU34 Solicitar orden de laboratorio (RF-35), CU35 Registrar
 * resultados de laboratorio (RF-36, RN23, RN24) y la revisión de CU36 (RF-37). Toda escritura va en UNA transacción con el registro bloqueado
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

    /**
     * CU35 pasos 4 a 9: registra la toma de muestra y los valores de una orden SOLICITADA o MUESTRA_TOMADA en
     * un solo paso. $datos: fecha_toma_muestra (Y-m-d H:i), laboratorio_procesador, valores y observaciones
     * por id de parámetro. Devuelve la orden y la cantidad de resultados críticos.
     *
     * @return array{0: OrdenLaboratorio, 1: int}
     */
    public function registrarResultados(OrdenLaboratorio $orden, array $datos, Usuario $usuario): array
    {
        return DB::transaction(function () use ($orden, $datos, $usuario) {
            // CU35 paso 4 (RN13): la orden bloqueada y leída de nuevo.
            $orden = OrdenLaboratorio::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            $orden->load(["tipoExamen.parametros", "consulta.cita.paciente.persona", "consulta.cita.ficha.horario.medicoEspecialidad.medico.persona"]);

            // CU35 paso 4: solo la Recepcionista (alcance «laboratorio»: laboratorio.cargar_resultados y sin perfil
            // de médico). El médico solo revisa (CU36), aunque la base todavía le tenga el permiso. Si no, 403.
            if (OrdenLaboratorio::alcance($usuario) !== "laboratorio") {
                throw new AuthorizationException("No puede registrar resultados de esta orden.");
            }

            // CU35 A2: otro usuario ya cargó los resultados o la orden se anuló.
            if (! in_array($orden->estado, OrdenLaboratorio::PENDIENTES, true)) {
                throw new ReglaNegocioException("La orden {$orden->codigo} ya no espera resultados: está " . mb_strtolower($orden->textoEstado()) . ".");
            }

            $parametros = $orden->tipoExamen->parametros;
            $persona = $orden->consulta->cita->paciente->persona;
            $ahora = now();

            // CU35 paso 5 / A3: todos los parámetros del examen con valor; los numéricos, un número válido.
            foreach ($parametros as $parametro) {
                $valor = $datos["valores"][$parametro->id] ?? null;
                if ($valor === null || $valor === "") {
                    throw new ReglaNegocioException("Falta el valor de {$parametro->nombre}.");
                }
                if ($parametro->tipo_valor === "NUMERICO" && ! is_numeric($valor)) {
                    throw new ReglaNegocioException("El valor de {$parametro->nombre} debe ser un número.");
                }
            }

            // CU35 paso 5 / A4: la toma de muestra no es futura ni anterior a la solicitud.
            $toma = Carbon::parse($datos["fecha_toma_muestra"]);
            if ($toma->gt($ahora) || $toma->lt($orden->fecha_solicitud->copy()->startOfMinute())) {
                throw new ReglaNegocioException("La fecha de toma de muestra debe estar entre la solicitud (" . $orden->fecha_solicitud->format("d/m/Y H:i") . ") y ahora.");
            }

            $estadoAnterior = $orden->estado;
            $criticos = collect();
            foreach ($parametros as $parametro) {
                $valor = $datos["valores"][$parametro->id];
                $numerico = $parametro->tipo_valor === "NUMERICO";

                // CU35 paso 6 (RN23) / A5: rango aplicable en la fecha del resultado e interpretación;
                // los de TEXTO o sin rango aplicable: sin rango y NO_APLICA.
                $rango = $numerico ? RangoReferencia::aplicable($parametro->id, $persona->fecha_nacimiento, $persona->sexo, $ahora) : null;

                // CU35 paso 7: un resultado por parámetro; el rango guardado no cambia después (RN23).
                $resultado = ResultadoLaboratorio::create([
                    "orden_laboratorio_id" => $orden->id,
                    "parametro_laboratorio_id" => $parametro->id,
                    "rango_referencia_id" => $rango?->id,
                    "usuario_carga_id" => $usuario->id,
                    "valor_numerico" => $numerico ? (float) $valor : null,
                    "valor_texto" => $numerico ? null : $valor,
                    "interpretacion" => $rango ? $rango->interpretar((float) $valor) : "NO_APLICA",
                    "fecha_resultado" => $ahora,
                    "observacion" => $datos["observaciones"][$parametro->id] ?? null,
                ]);
                if ($resultado->esCritico()) {
                    $criticos->push($resultado->setRelation("parametro", $parametro));
                }
            }

            // CU35 paso 7: la orden con la toma de muestra, el laboratorio y RESULTADO_LISTO.
            $orden->update([
                "fecha_toma_muestra" => $toma,
                "laboratorio_procesador" => $datos["laboratorio_procesador"] ?? null,
                "estado" => "RESULTADO_LISTO",
            ]);

            // CU35 paso 8 (RN24): avisos al médico solicitante.
            $this->avisarResultados($orden, $persona, $criticos, $ahora);

            // CU35 paso 9: bitácora como BitacoraSeeder.
            Bitacora::registrar("CARGAR_RESULTADOS", "laboratorio", $usuario->id, true, "ordenes_laboratorio", $orden->id, "WEB",
                ["estado" => $estadoAnterior],
                ["codigo" => $orden->codigo, "estado" => "RESULTADO_LISTO", "parametros" => $parametros->count(), "criticos" => $criticos->count()]);

            return [$orden, $criticos->count()];
        });
    }

    /**
     * CU36 paso 3: el médico solicitante abre una orden RESULTADO_LISTO y pasa a REVISADA. Nadie más la marca
     * (CU36 A4). Devuelve true si cambió.
     */
    public function marcarRevisada(OrdenLaboratorio $orden, Usuario $usuario): bool
    {
        if ($orden->estado !== "RESULTADO_LISTO" || ! $orden->esSolicitante($usuario)) {
            return false;
        }

        return DB::transaction(function () use ($orden, $usuario) {
            $bloqueada = OrdenLaboratorio::whereKey($orden->id)->lockForUpdate()->firstOrFail();
            // Otro pedido del mismo médico pudo marcarla un instante antes.
            if ($bloqueada->estado !== "RESULTADO_LISTO") {
                return false;
            }

            $bloqueada->update(["estado" => "REVISADA", "fecha_revision" => now()]);
            $orden->setRawAttributes($bloqueada->getAttributes(), true);

            Bitacora::registrar("REVISAR_RESULTADOS", "laboratorio", $usuario->id, true, "ordenes_laboratorio", $orden->id, "WEB",
                ["estado" => "RESULTADO_LISTO"], ["estado" => "REVISADA"]);

            return true;
        });
    }

    /**
     * CU35 paso 8 (RN24): RESULTADO_LISTO por el sistema y, por cada resultado crítico, VALOR_CRITICO por el
     * sistema y por correo; solo al médico solicitante, PENDIENTES y programados para ahora (el envío es
     * CU37). Mismos textos que NotificacionSeeder.
     */
    private function avisarResultados(OrdenLaboratorio $orden, Persona $paciente, $criticos, Carbon $ahora): void
    {
        $medico = $orden->medicoSolicitante()->persona;
        $nombre = "{$paciente->nombres} {$paciente->apellido_paterno}";
        $base = ["persona_id" => $medico->id, "programada_para" => $ahora, "estado" => "PENDIENTE"];

        Notificacion::create($base + [
            "tipo" => "RESULTADO_LISTO", "canal" => "SISTEMA",
            "referencia_tipo" => "ordenes_laboratorio", "referencia_id" => $orden->id,
            "titulo" => "Resultados de {$orden->codigo} disponibles",
            "mensaje" => "Ya están los resultados de {$orden->tipoExamen->nombre} de {$nombre} (orden {$orden->codigo}).",
        ]);

        foreach ($criticos as $resultado) {
            $parametro = $resultado->parametro;
            $valor = rtrim(rtrim(number_format((float) $resultado->valor_numerico, 2, ",", ""), "0"), ",");
            foreach (["SISTEMA", "CORREO"] as $canal) {
                Notificacion::create($base + [
                    "tipo" => "VALOR_CRITICO", "canal" => $canal,
                    "referencia_tipo" => "resultados_laboratorio", "referencia_id" => $resultado->id,
                    "titulo" => "Valor crítico: {$parametro->nombre}",
                    "mensaje" => "VALOR CRÍTICO en {$orden->codigo}: {$parametro->nombre} = {$valor} {$parametro->unidad} ({$nombre}). Requiere atención inmediata.",
                ]);
            }
        }
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
