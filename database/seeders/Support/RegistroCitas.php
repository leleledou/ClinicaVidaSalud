<?php

namespace Database\Seeders\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Inserta una cita con su historial coherente (HistorialCita es de solo
 * inserción: nunca se modifica ni se borra).
 *
 * Una reprogramación (mismo médico) conserva la cita y cambia su ficha; queda
 * como una fila de historial PROGRAMADA → PROGRAMADA con ficha anterior y
 * nueva. La reasignación a otro médico es una cita nueva que REEMPLAZA a una
 * cancelada (cita_reemplazada_id).
 *
 * ANTES de escribir, planificar() valida el escenario y lanza SiembraInvalida si:
 *  - la cronología es imposible (evento posterior a otro que depende de él,
 *    evento en el futuro, registro previo al alta del paciente, a la
 *    verificación de su responsable legal, de su seguro o de su correo);
 *  - se incumple una regla que la base no impone: edad y sexo de la
 *    especialidad (salvo excepción autorizada), responsable legal verificado en
 *    menores, médico y paciente activos en reservas nuevas, seguro propio,
 *    verificado y vigente, citas cruzadas del mismo paciente.
 * Son controles de la propia siembra, no un servicio de negocio.
 *
 * Las citas atendidas, con inasistencia o canceladas en el pasado son un
 * histórico controlado (su ficha ya venció). Las PROGRAMADA/CONFIRMADA exigen
 * fichas futuras.
 */
final class RegistroCitas
{
    /**
     * @param array<string,mixed> $d
     *   codigo, paciente_id, usuario_id, ficha_id (ficha vigente de la cita), fichas_previas[],
     *   estado, origen, canal, motivo, seguro_id, codigo_autorizacion, enlace_virtual,
     *   motivo_cancelacion, admin_id, justificacion, reemplaza_id, usuario_atiende_id,
     *   usuario_confirma_id, confirmar (bool), motivo_reprogramacion,
     *   registro_horas (horas antes de «ahora» en que se registra; opcional),
     *   cancela_horas (horas antes de «ahora» en que se cancela; opcional)
     * @return int|null id de la cita, o null si ya existía (idempotencia por código)
     */
    public static function registrar(array $d): ?int
    {
        if (Siembra::id('citas', ['codigo' => $d['codigo']]) !== null) {
            return null;
        }

        $pasos = self::planificar($d);

        return DB::transaction(function () use ($d, $pasos) {
            $citaId = (int) DB::table('citas')->insertGetId([
                'usuario_registra_id' => $d['usuario_id'],
                'paciente_id' => $d['paciente_id'],
                'ficha_id' => $d['ficha_id'],
                'seguro_paciente_id' => $d['seguro_id'] ?? null,
                'administrador_autoriza_id' => $d['admin_id'] ?? null,
                'cita_reemplazada_id' => $d['reemplaza_id'] ?? null,
                'codigo' => $d['codigo'],
                'origen' => $d['origen'],
                'canal' => $d['canal'],
                'motivo' => $d['motivo'] ?? null,
                'estado' => $d['estado'],
                'codigo_autorizacion_seguro' => $d['codigo_autorizacion'] ?? null,
                'enlace_virtual' => $d['enlace_virtual'] ?? null,
                'motivo_cancelacion' => $d['estado'] === 'CANCELADA' ? $d['motivo_cancelacion'] : null,
                'justificacion_excepcion' => $d['justificacion'] ?? null,
                'fecha_registro' => Siembra::ts($pasos[0][4]),
            ]);

            foreach ($pasos as [$anterior, $nuevo, $quien, $via, $cuando, $fAnt, $fNue, $motivo]) {
                DB::table('historiales_cita')->insert([
                    'cita_id' => $citaId,
                    'usuario_realiza_id' => $quien,
                    'ficha_anterior_id' => $fAnt,
                    'ficha_nueva_id' => $fNue,
                    'estado_anterior' => $anterior,
                    'estado_nuevo' => $nuevo,
                    'canal' => $via,
                    'motivo' => $motivo,
                    'fecha_hora' => Siembra::ts($cuando),
                ]);
            }

            return $citaId;
        });
    }

    /**
     * Valida el escenario y construye la línea de tiempo del historial.
     *
     * @param array<string,mixed> $d
     * @return list<array{0:?string,1:string,2:?int,3:string,4:CarbonImmutable,5:?int,6:?int,7:?string}>
     */
    public static function planificar(array $d): array
    {
        $codigo = $d['codigo'];
        $fallo = fn (string $motivo) => new SiembraInvalida("Cita {$codigo}: {$motivo}");

        $ahora = Siembra::ahora();
        $estado = $d['estado'];
        $usuario = $d['usuario_id'];
        $canal = $d['canal'];
        $futura = in_array($estado, ['PROGRAMADA', 'CONFIRMADA'], true);

        // ---------------------------------------------------------------- ficha y médico
        $ficha = self::fichaConContexto((int) $d['ficha_id']);
        $inicio = Siembra::instante($ficha->fecha, $ficha->hora_inicio);
        $fin = Siembra::instante($ficha->fecha, $ficha->hora_fin);

        if ($ficha->ficha_estado !== 'HABILITADA') {
            throw $fallo('la ficha está bloqueada');
        }
        if ($futura && $inicio->lte($ahora)) {
            throw $fallo("{$estado} requiere una ficha futura");
        }
        if (in_array($estado, ['ATENDIDA', 'INASISTENCIA'], true) && $fin->addMinutes(5)->gte($ahora)) {
            throw $fallo("{$estado} requiere una ficha ya vencida");
        }
        if ($futura && $ficha->medico_estado !== 'ACTIVO') {
            throw $fallo('una reserva nueva exige un médico activo (RN04)');
        }

        // ---------------------------------------------------------------- paciente
        $p = DB::table('pacientes as p')->join('personas as pe', 'pe.id', '=', 'p.persona_id')
            ->where('p.id', $d['paciente_id'])
            ->first(['p.persona_id', 'p.estado', 'p.fecha_registro', 'pe.fecha_nacimiento', 'pe.sexo']);
        if ($p === null) {
            throw $fallo('el paciente no existe');
        }
        if ($futura && $p->estado !== 'ACTIVO') {
            throw $fallo('una reserva nueva exige un paciente activo');
        }

        $edad = (int) date_diff(date_create($p->fecha_nacimiento), date_create($ficha->fecha))->y;
        if (empty($d['admin_id'])) {
            if (($ficha->edad_minima !== null && $edad < $ficha->edad_minima) || ($ficha->edad_maxima !== null && $edad > $ficha->edad_maxima)) {
                throw $fallo("edad {$edad} fuera del rango de {$ficha->especialidad} (RN15)");
            }
            if ($ficha->sexo_permitido !== null && $ficha->sexo_permitido !== $p->sexo) {
                throw $fallo("{$ficha->especialidad} no atiende ese sexo (RN15)");
            }
        }

        // Instante mínimo de registro: el alta y cada verificación que la reserva necesita.
        $minimo = Siembra::desdeTimestamp($p->fecha_registro)->addMinutes(30);

        if ($edad < 18) {
            $verificado = DB::table('vinculos_paciente')
                ->where('paciente_id', $d['paciente_id'])->where('estado', 'ACTIVO')
                ->where('es_responsable_legal', true)->whereNotNull('fecha_verificacion')
                ->min('fecha_verificacion');
            if ($verificado === null) {
                throw $fallo('menor sin responsable legal verificado (RN16)');
            }
            $minimo = self::mayor($minimo, Siembra::desdeTimestamp($verificado)->addMinutes(5));
        }

        if (! empty($d['seguro_id'])) {
            $s = DB::table('seguros_paciente')->where('id', $d['seguro_id'])->first();
            if ($s === null || (int) $s->paciente_id !== (int) $d['paciente_id']) {
                throw $fallo('el seguro no pertenece al paciente (RN20)');
            }
            if ($s->estado !== 'ACTIVO' || $s->fecha_verificacion === null) {
                throw $fallo('el seguro no está activo y verificado (RN20)');
            }
            if ($ficha->fecha < $s->vigente_desde || $ficha->fecha > $s->vigente_hasta) {
                throw $fallo('el seguro no está vigente en la fecha de la cita (RN20)');
            }
            $minimo = self::mayor($minimo, Siembra::desdeTimestamp($s->fecha_verificacion)->addMinutes(5));
        }

        if (($d['origen'] ?? '') === 'AUTOSERVICIO') {
            $u = DB::table('usuarios')->where('id', $usuario)->first(['persona_id', 'correo_verificado_en']);
            if ($u === null || $u->correo_verificado_en === null) {
                throw $fallo('la reserva por autoservicio exige el correo verificado');
            }
            if ((int) $u->persona_id !== (int) $p->persona_id) {
                $responsable = DB::table('vinculos_paciente')
                    ->where('paciente_id', $d['paciente_id'])->where('persona_id', $u->persona_id)
                    ->where('estado', 'ACTIVO')->where('es_responsable_legal', true)
                    ->whereNotNull('fecha_verificacion')->exists();
                if (! $responsable) {
                    throw $fallo('el usuario no es el paciente ni su responsable legal verificado');
                }
            }
            $minimo = self::mayor($minimo, Siembra::desdeTimestamp($u->correo_verificado_en)->addMinutes(5));
        }

        if ($estado !== 'CANCELADA') {
            $cruce = DB::table('citas as c')->join('fichas as f', 'f.id', '=', 'c.ficha_id')
                ->where('c.paciente_id', $d['paciente_id'])->where('c.estado', '<>', 'CANCELADA')
                ->where('f.fecha', $ficha->fecha)
                ->where('f.hora_inicio', '<', $ficha->hora_fin)->where('f.hora_fin', '>', $ficha->hora_inicio)
                ->value('c.codigo');
            if ($cruce !== null) {
                throw $fallo("el paciente ya tiene la cita {$cruce} a esa hora (RN17)");
            }
        }

        // ---------------------------------------------------------------- línea de tiempo
        if (isset($d['registro_horas'])) {
            $t = $ahora->subHours((int) $d['registro_horas']);
            if ($t->lt($minimo)) {
                throw $fallo('el registro indicado es anterior al alta o a una verificación necesaria');
            }
        } else {
            $t = self::menor($inicio->subDays(5), $ahora->subHours(26));
            if ($t->lt($minimo)) {
                $t = $minimo;
            }
        }
        if ($t->gt($ahora->subMinutes(30)) || $t->gte($inicio->subHour())) {
            throw $fallo('no hay un instante de registro coherente entre las verificaciones necesarias y la ficha');
        }

        $previas = $d['fichas_previas'] ?? [];
        $cadena = array_merge($previas, [$d['ficha_id']]);
        $pasos = [];
        $pasos[] = [null, 'PROGRAMADA', $usuario, $canal, $t, null, $cadena[0], $d['motivo_registro'] ?? 'Registro de la cita'];
        $ultimo = $t;

        foreach ($previas as $i => $fichaPrevia) {
            $ultimo = $ultimo->addHours(2);
            $f = DB::table('fichas')->where('id', $fichaPrevia)->first(['fecha', 'hora_inicio']);
            if ($f === null || $ultimo->gte(Siembra::instante($f->fecha, $f->hora_inicio))) {
                throw $fallo('la reprogramación sería posterior al inicio de la ficha anterior');
            }
            $pasos[] = ['PROGRAMADA', 'PROGRAMADA', $usuario, $canal, $ultimo, $cadena[$i], $cadena[$i + 1],
                $d['motivo_reprogramacion'] ?? 'Reprogramación con el mismo médico'];
        }

        $previo = 'PROGRAMADA';
        if ($estado === 'CONFIRMADA' || (($d['confirmar'] ?? false) && $estado === 'ATENDIDA')) {
            $ultimo = self::despues($ultimo, self::menor($inicio->subDay(), $ahora->subHours(2)));
            $pasos[] = ['PROGRAMADA', 'CONFIRMADA', $d['usuario_confirma_id'] ?? $usuario, $canal, $ultimo, null, null, 'Asistencia confirmada'];
            $previo = 'CONFIRMADA';
        }
        if ($estado === 'ATENDIDA') {
            $ultimo = $fin->addMinutes(5);
            $pasos[] = [$previo, 'ATENDIDA', $d['usuario_atiende_id'] ?? $usuario, 'WEB', $ultimo, null, null, 'Atención registrada'];
        } elseif ($estado === 'INASISTENCIA') {
            // Marcada por el Temporizador una vez vencida la tolerancia de 15 minutos.
            $ultimo = $inicio->addMinutes(20);
            $pasos[] = [$previo, 'INASISTENCIA', null, 'SISTEMA', $ultimo, null, null, 'Inasistencia marcada por el sistema'];
        } elseif ($estado === 'CANCELADA') {
            if (isset($d['cancela_horas'])) {
                $ultimo = $ahora->subHours((int) $d['cancela_horas']);
                if ($ultimo->lte($pasos[array_key_last($pasos)][4])) {
                    throw $fallo('la cancelación indicada no es posterior al evento anterior');
                }
            } else {
                $ultimo = self::despues($ultimo, self::menor($inicio->subDay(), $ahora->subHour()));
            }
            $pasos[] = [$previo, 'CANCELADA', $usuario, $canal, $ultimo, null, null, $d['motivo_cancelacion']];
        }

        // Comprobación final: orden estricto y ningún evento en el futuro ni a partir de la ficha cancelada/confirmada.
        $anterior = null;
        foreach ($pasos as $paso) {
            $cuando = $paso[4];
            if ($cuando->gt($ahora) || ($anterior !== null && $cuando->lte($anterior))) {
                throw $fallo("el historial queda fuera de orden o en el futuro ({$paso[1]})");
            }
            if (in_array($paso[1], ['CONFIRMADA', 'CANCELADA'], true) && $cuando->gte($inicio)) {
                throw $fallo("{$paso[1]} debe ocurrir antes de la hora de la ficha (RN21)");
            }
            $anterior = $cuando;
        }

        return $pasos;
    }

    private static function fichaConContexto(int $id): object
    {
        return DB::table('fichas as f')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('medicos as m', 'm.id', '=', 'me.medico_id')
            ->join('especialidades as e', 'e.id', '=', 'me.especialidad_id')
            ->where('f.id', $id)
            ->first(['f.fecha', 'f.hora_inicio', 'f.hora_fin', 'f.estado as ficha_estado', 'm.estado as medico_estado',
                'e.nombre as especialidad', 'e.edad_minima', 'e.edad_maxima', 'e.sexo_permitido'])
            ?? throw new SiembraInvalida("La ficha {$id} no existe.");
    }

    /** Garantiza que cada paso del historial sea posterior al anterior. */
    private static function despues(CarbonImmutable $ultimo, CarbonImmutable $deseado): CarbonImmutable
    {
        $minimo = $ultimo->addMinutes(30);

        return $deseado->lt($minimo) ? $minimo : $deseado;
    }

    private static function menor(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->lt($b) ? $a : $b;
    }

    private static function mayor(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->gt($b) ? $a : $b;
    }
}
