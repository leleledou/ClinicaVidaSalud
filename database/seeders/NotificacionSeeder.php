<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Notificaciones derivadas de los datos ya sembrados (perfiles demo y extendido).
 * Reproduce lo que habría generado la aplicación (CitaService::crearNotificaciones):
 *
 *  - Por cada evento del historial de una cita, al paciente (o a su responsable
 *    legal verificado si es menor en la fecha de la cita) y al médico:
 *      registro → CITA_PROGRAMADA · reprogramación → CITA_REPROGRAMADA ·
 *      cancelación → CITA_CANCELADA.
 *  - Un RECORDATORIO_CITA al paciente por cada ficha que tuvo la cita, a las
 *    horas_anticipacion de su cuenta (24 si no tiene), solo si ese momento era
 *    futuro cuando se fijó la ficha. Si la cita se reprogramó o se canceló antes
 *    del recordatorio, ese recordatorio queda CANCELADA (no se elimina).
 *  - RESULTADO_LISTO al médico solicitante por cada orden con resultados, y
 *    VALOR_CRITICO (por el sistema y por correo) por cada resultado crítico.
 *  - VERIFICAR_CORREO a cada paciente que se autorregistró con cuenta.
 *
 * Canal: el médico siempre SISTEMA; el paciente CORREO si su cuenta acepta
 * avisos por correo, PUSH para los recordatorios si activó las notificaciones
 * push, y SISTEMA si no tiene cuenta.
 *
 * Estado según «ahora»: lo programado para el futuro queda PENDIENTE; lo pasado
 * ENVIADA o LEIDA, con algún envío por CORREO o PUSH FALLIDA (intentos > 0).
 * La elección es determinista (crc32 de la clave natural).
 *
 * Idempotente: clave natural (persona, tipo, canal, referencia, programada_para),
 * todas derivadas de datos ya guardados. Nunca actualiza una fila existente.
 */
class NotificacionSeeder extends Seeder
{
    /** @var array<string,true> */
    private array $existentes = [];

    /** @var list<array<string,mixed>> */
    private array $nuevas = [];

    private CarbonImmutable $ahora;

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);
        $this->ahora = Siembra::ahora();

        DB::transaction(function () {
            foreach (DB::table('notificaciones')->get(['persona_id', 'tipo', 'canal', 'referencia_tipo', 'referencia_id', 'programada_para']) as $n) {
                $this->existentes[$this->clave($n->persona_id, $n->tipo, $n->canal, $n->referencia_tipo, $n->referencia_id, $n->programada_para)] = true;
            }

            $this->citas();
            $this->laboratorio();
            $this->verificacionCorreo();

            foreach (array_chunk($this->nuevas, 500) as $lote) {
                DB::table('notificaciones')->insert($lote);
            }
            $this->command?->info('Notificaciones: '.count($this->nuevas).' nuevas.');
        });
    }

    // ------------------------------------------------------------------ citas

    private function citas(): void
    {
        $fichas = DB::table('fichas as f')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('medicos as m', 'm.id', '=', 'me.medico_id')
            ->join('personas as pm', 'pm.id', '=', 'm.persona_id')
            ->join('especialidades as e', 'e.id', '=', 'me.especialidad_id')
            ->leftJoin('consultorios as co', 'co.id', '=', 'h.consultorio_id')
            ->whereIn('f.id', DB::table('historiales_cita')->select('ficha_nueva_id')->whereNotNull('ficha_nueva_id'))
            ->get(['f.id', 'f.fecha', 'f.hora_inicio', 'f.hora_fin', 'h.modalidad', 'co.nombre as consultorio', 'co.ubicacion',
                'm.persona_id as medico_persona', 'pm.sexo as medico_sexo', 'e.nombre as especialidad',
                DB::raw("pm.nombres || ' ' || pm.apellido_paterno || coalesce(' ' || pm.apellido_materno, '') as medico")])
            ->keyBy('id');

        $citas = DB::table('citas as c')->join('pacientes as p', 'p.id', '=', 'c.paciente_id')
            ->join('personas as pe', 'pe.id', '=', 'p.persona_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'c.usuario_registra_id')
            ->orderBy('c.id')
            ->get(['c.id', 'c.codigo', 'c.paciente_id', 'c.motivo_cancelacion', 'c.enlace_virtual', 'p.persona_id', 'pe.fecha_nacimiento',
                'u.persona_id as registra_persona',
                DB::raw("pe.nombres || ' ' || pe.apellido_paterno || coalesce(' ' || pe.apellido_materno, '') as paciente")]);

        $historial = DB::table('historiales_cita')->orderBy('fecha_hora')->orderBy('id')->get()->groupBy('cita_id');
        $responsables = DB::table('vinculos_paciente')->where('estado', 'ACTIVO')->where('es_responsable_legal', true)
            ->whereNotNull('fecha_verificacion')->orderBy('id')->get(['paciente_id', 'persona_id'])->groupBy('paciente_id');
        $cuentas = DB::table('usuarios')->get(['persona_id', 'correo', 'notificar_por_correo', 'notificar_por_push', 'horas_anticipacion'])->keyBy('persona_id');

        foreach ($citas as $c) {
            $eventos = $historial[$c->id] ?? collect();
            if ($eventos->isEmpty()) {
                continue;
            }
            $primera = $fichas[$eventos->first()->ficha_nueva_id];

            // Destinatario: el paciente o, si es menor en la fecha de la cita, su responsable legal verificado
            // (de preferencia quien registró la cita, como hace la aplicación).
            $destino = (int) $c->persona_id;
            if (date_diff(date_create($c->fecha_nacimiento), date_create($primera->fecha))->y < 18) {
                $lista = $responsables[$c->paciente_id] ?? collect();
                $destino = (int) ($lista->firstWhere('persona_id', $c->registra_persona)?->persona_id ?? $lista->first()?->persona_id ?? $destino);
            }
            $cuenta = $cuentas[$destino] ?? null;
            $canal = $cuenta && $cuenta->notificar_por_correo && $cuenta->correo ? 'CORREO' : 'SISTEMA';
            $canalRecordatorio = $cuenta && $cuenta->notificar_por_push ? 'PUSH' : $canal;
            $horas = (int) ($cuenta->horas_anticipacion ?? 24);

            // Fichas que tuvo la cita y desde cuándo hasta cuándo estuvo en cada una.
            $tramos = [];
            $ficha = null;
            foreach ($eventos as $ev) {
                $cuando = Siembra::desdeTimestamp($ev->fecha_hora);
                $tipo = match (true) {
                    $ev->estado_anterior === null => 'CITA_PROGRAMADA',
                    $ev->ficha_anterior_id !== null && $ev->ficha_nueva_id !== null => 'CITA_REPROGRAMADA',
                    $ev->estado_nuevo === 'CANCELADA' => 'CITA_CANCELADA',
                    default => null,
                };
                if ($tipo === null) {
                    continue; // confirmación, atención o inasistencia: sin aviso
                }
                if ($ev->ficha_nueva_id !== null) {
                    if ($tramos !== []) {
                        $tramos[array_key_last($tramos)][2] = $cuando;
                    }
                    $ficha = $fichas[$ev->ficha_nueva_id];
                    $tramos[] = [$ficha, $cuando, null];
                } elseif ($tipo === 'CITA_CANCELADA' && $tramos !== []) {
                    $tramos[array_key_last($tramos)][2] = $cuando;
                }
                [$tp, $mp, $tm, $mm] = $this->textos($tipo, $c, $ficha);
                $this->agregar($destino, $tipo, $canal, 'citas', $c->id, $cuando, $tp, $mp);
                $this->agregar((int) $ficha->medico_persona, $tipo, 'SISTEMA', 'citas', $c->id, $cuando, $tm, $mm);
            }

            foreach ($tramos as [$f, $desde, $hasta]) {
                $recordar = Siembra::instante($f->fecha, $f->hora_inicio)->subHours($horas);
                if ($recordar->lte($desde)) {
                    continue; // la aplicación no crea recordatorios ya vencidos
                }
                $anulado = $hasta !== null && $recordar->gte($hasta);
                $this->agregar($destino, 'RECORDATORIO_CITA', $canalRecordatorio, 'citas', $c->id, $recordar,
                    "Recordatorio de la cita {$c->codigo}", "Le recordamos la cita {$c->codigo} de {$this->detalle($c, $f)}.", $anulado);
            }
        }
    }

    /** @return array{string,string,string,string} título y mensaje para el paciente y para el médico */
    private function textos(string $tipo, object $c, object $f): array
    {
        $detalle = $this->detalle($c, $f);
        $cuando = $this->cuando($f);

        return match ($tipo) {
            'CITA_REPROGRAMADA' => [
                "Cita {$c->codigo} reprogramada", "La cita {$c->codigo} se reprogramó: {$detalle}. Debe confirmarse otra vez.",
                "Cita {$c->codigo} reprogramada", "La cita {$c->codigo} de {$c->paciente} se reprogramó al {$cuando}.",
            ],
            'CITA_CANCELADA' => [
                "Cita {$c->codigo} cancelada", "Se canceló la cita {$c->codigo} de {$detalle}. Motivo: {$c->motivo_cancelacion}",
                "Cita {$c->codigo} cancelada", "Se canceló la cita {$c->codigo} de {$c->paciente} del {$cuando}. El horario quedó disponible.",
            ],
            default => [
                "Cita {$c->codigo} programada", "Se programó la cita {$c->codigo} de {$detalle}.",
                "Nueva cita {$c->codigo}", "Nueva cita {$c->codigo}: {$c->paciente} el {$cuando}.",
            ],
        };
    }

    private function cuando(object $f): string
    {
        return CarbonImmutable::parse($f->fecha)->format('d/m/Y').' de '.substr($f->hora_inicio, 0, 5).' a '.substr($f->hora_fin, 0, 5);
    }

    private function detalle(object $c, object $f): string
    {
        $lugar = $f->modalidad === 'VIRTUAL'
            ? 'en línea (enlace: '.($c->enlace_virtual ?? 'se enviará antes de la cita').')'
            : "en {$f->consultorio} ({$f->ubicacion})";
        $medico = ($f->medico_sexo === 'F' ? 'Dra. ' : 'Dr. ').$f->medico;

        return "{$c->paciente} con {$medico} ({$f->especialidad}) el {$this->cuando($f)} {$lugar}";
    }

    // ------------------------------------------------------------ laboratorio

    private function laboratorio(): void
    {
        $ordenes = DB::table('ordenes_laboratorio as o')
            ->join('consultas as co', 'co.id', '=', 'o.consulta_id')
            ->join('citas as c', 'c.id', '=', 'co.cita_id')
            ->join('fichas as f', 'f.id', '=', 'c.ficha_id')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('medicos as m', 'm.id', '=', 'me.medico_id')
            ->join('tipos_examen as t', 't.id', '=', 'o.tipo_examen_id')
            ->join('pacientes as p', 'p.id', '=', 'c.paciente_id')
            ->join('personas as pe', 'pe.id', '=', 'p.persona_id')
            ->whereIn('o.estado', ['RESULTADO_LISTO', 'REVISADA'])
            ->orderBy('o.id')
            ->get(['o.id', 'o.codigo', 'o.estado', 'o.fecha_revision', 'm.persona_id as medico', 't.nombre as examen',
                DB::raw("pe.nombres || ' ' || pe.apellido_paterno as paciente")]);
        $resultados = DB::table('resultados_laboratorio as r')->join('parametros_laboratorio as pl', 'pl.id', '=', 'r.parametro_laboratorio_id')
            ->orderBy('r.id')->get(['r.id', 'r.orden_laboratorio_id', 'r.fecha_resultado', 'r.interpretacion', 'r.valor_numerico', 'pl.nombre', 'pl.unidad'])
            ->groupBy('orden_laboratorio_id');

        foreach ($ordenes as $o) {
            $filas = $resultados[$o->id] ?? collect();
            if ($filas->isEmpty()) {
                continue;
            }
            $listo = Siembra::desdeTimestamp((string) $filas->max('fecha_resultado'));
            $leida = $o->fecha_revision === null ? null : Siembra::desdeTimestamp($o->fecha_revision);
            $this->agregar((int) $o->medico, 'RESULTADO_LISTO', 'SISTEMA', 'ordenes_laboratorio', $o->id, $listo,
                "Resultados de {$o->codigo} disponibles", "Ya están los resultados de {$o->examen} de {$o->paciente} (orden {$o->codigo}).",
                false, $leida ?? false);

            foreach ($filas->whereIn('interpretacion', ['CRITICO_BAJO', 'CRITICO_ALTO']) as $r) {
                $cuando = Siembra::desdeTimestamp($r->fecha_resultado);
                $valor = rtrim(rtrim(number_format((float) $r->valor_numerico, 2, ',', ''), '0'), ',');
                $mensaje = "VALOR CRÍTICO en {$o->codigo}: {$r->nombre} = {$valor} {$r->unidad} ({$o->paciente}). Requiere atención inmediata.";
                foreach (['SISTEMA', 'CORREO'] as $canal) {
                    $this->agregar((int) $o->medico, 'VALOR_CRITICO', $canal, 'resultados_laboratorio', $r->id, $cuando,
                        "Valor crítico: {$r->nombre}", $mensaje, false, $canal === 'SISTEMA' ? ($leida ?? false) : false);
                }
            }
        }
    }

    // ------------------------------------------------------- verificar correo

    private function verificacionCorreo(): void
    {
        $filas = DB::table('pacientes as p')->join('usuarios as u', 'u.persona_id', '=', 'p.persona_id')
            ->whereNull('p.recepcionista_registra_id')->where('u.rol_id', Siembra::rolId('PACIENTE'))
            ->orderBy('u.id')->get(['u.id', 'u.persona_id', 'u.correo', 'u.correo_verificado_en', 'p.fecha_registro']);

        foreach ($filas as $u) {
            $enviado = Siembra::desdeTimestamp($u->fecha_registro)->addMinute();
            $verificado = $u->correo_verificado_en === null ? false : Siembra::desdeTimestamp($u->correo_verificado_en);
            $this->agregar((int) $u->persona_id, 'VERIFICAR_CORREO', 'CORREO', 'usuarios', $u->id, $enviado,
                'Verifique su correo', "Confirme su correo {$u->correo} para activar la reserva de citas en línea.", false, $verificado);
        }
    }

    // ----------------------------------------------------------------- común

    private function clave(int|string $persona, string $tipo, string $canal, ?string $refTipo, int|string|null $refId, string $programada): string
    {
        return implode('|', [$persona, $tipo, $canal, $refTipo, $refId, substr($programada, 0, 19)]);
    }

    /**
     * Agrega la notificación si no existe. Estado:
     *  - anulada → CANCELADA; programada en el futuro → PENDIENTE;
     *  - pasada → ENVIADA, LEIDA o (CORREO/PUSH) FALLIDA, según crc32 de su clave.
     * $leida: un instante fija la lectura en ese momento, false la deja sin leer (ENVIADA),
     * null la decide el azar determinista.
     */
    private function agregar(int $persona, string $tipo, string $canal, string $refTipo, int $refId, CarbonImmutable $programada,
        string $titulo, string $mensaje, bool $anulada = false, CarbonImmutable|false|null $leida = null): void
    {
        $ts = Siembra::ts($programada);
        $clave = $this->clave($persona, $tipo, $canal, $refTipo, $refId, $ts);
        if (isset($this->existentes[$clave])) {
            return;
        }
        $this->existentes[$clave] = true;

        $h = crc32($clave) % 100;
        $estado = 'PENDIENTE';
        $enviada = null;
        $leidaEn = null;
        $intentos = 0;
        if ($anulada) {
            $estado = 'CANCELADA';
        } elseif ($programada->lte($this->ahora)) {
            $enviada = $programada->addMinutes(1 + $h % 4);
            $falla = match ($canal) { 'CORREO' => 4, 'PUSH' => 6, default => 0 };
            if ($leida === null && $h < $falla) {
                [$estado, $enviada, $intentos] = ['FALLIDA', null, 3];
            } else {
                $intentos = 1;
                $leidaEn = match (true) {
                    $leida instanceof CarbonImmutable => $leida->max($enviada),
                    $leida === false => null,
                    $h < ($canal === 'CORREO' ? 55 : 35) => null,
                    default => $enviada->addMinutes(10 + ($h * 37) % 1440),
                };
                if ($enviada->gt($this->ahora)) {
                    [$estado, $enviada, $leidaEn, $intentos] = ['PENDIENTE', null, null, 0];
                } elseif ($leidaEn !== null && $leidaEn->gt($this->ahora)) {
                    $leidaEn = null;
                }
                if ($enviada !== null) {
                    $estado = $leidaEn === null ? 'ENVIADA' : 'LEIDA';
                }
            }
        }

        $this->nuevas[] = [
            'persona_id' => $persona,
            'tipo' => $tipo,
            'canal' => $canal,
            'titulo' => mb_substr($titulo, 0, 255),
            'mensaje' => $mensaje,
            'referencia_tipo' => $refTipo,
            'referencia_id' => $refId,
            'programada_para' => $ts,
            'enviada_en' => $enviada === null ? null : Siembra::ts($enviada),
            'leida_en' => $leidaEn === null ? null : Siembra::ts($leidaEn),
            'estado' => $estado,
            'intentos' => $intentos,
        ];
    }
}
