<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora (solo inserción) derivada de los datos ya sembrados, con las mismas
 * acciones y módulos que registra la aplicación (Bitacora::registrar):
 *
 *  seguridad    INICIO_SESION, CIERRE_SESION, LOGIN_FALLIDO (exito = false),
 *               CAMBIO_PASSWORD, AUTORREGISTRO, VERIFICAR_CORREO
 *  pacientes    CREAR_PACIENTE (altas hechas en recepción)
 *  citas        PROGRAMAR_CITA, REASIGNAR_CITA, REPROGRAMAR_CITA, CANCELAR_CITA,
 *               CAMBIO_ESTADO_CITA (confirmación, atención y, por SISTEMA, inasistencia)
 *  consultas    FINALIZAR_CONSULTA, REGISTRAR_TRATAMIENTO
 *  laboratorio  SOLICITAR_EXAMEN, ANULAR_ORDEN, CARGAR_RESULTADOS, REVISAR_RESULTADOS
 *
 * Cada acción de un usuario va precedida de su INICIO_SESION ese día (uno por
 * usuario y día) y a veces de un intento fallido. Canal: WEB casi siempre,
 * SISTEMA para lo automático (inasistencias) y VOZ cuando la cita se gestionó
 * por voz, con su transcripcion_voz (ck_bitacoras_voz: solo con canal VOZ).
 *
 * NUNCA se guarda una contraseña (ni su hash) en datos_anteriores ni en
 * datos_nuevos: CAMBIO_PASSWORD no lleva datos.
 *
 * Idempotente: clave (acción, usuario, tabla, registro, fecha_hora), todas
 * derivadas de datos ya guardados. La tabla no admite UPDATE ni DELETE.
 */
class BitacoraSeeder extends Seeder
{
    /** @var array<string,true> */
    private array $existentes = [];

    /** @var list<array<string,mixed>> */
    private array $filas = [];

    /** @var array<int,array<string,array{CarbonImmutable,CarbonImmutable,string}>> usuario => día => [primera, última, canal] */
    private array $jornadas = [];

    /** @var array<int,string> */
    private array $roles = [];

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);

        DB::transaction(function () {
            foreach (DB::table('bitacoras')->get(['accion', 'usuario_id', 'tabla_afectada', 'registro_id', 'fecha_hora']) as $b) {
                $this->existentes[$this->clave($b->accion, $b->usuario_id, $b->tabla_afectada, $b->registro_id, $b->fecha_hora)] = true;
            }
            $this->roles = DB::table('usuarios as u')->join('roles as r', 'r.id', '=', 'u.rol_id')->pluck('r.nombre', 'u.id')->all();

            $this->pacientes();
            $this->citas();
            $this->clinica();
            $this->sesiones();
            $this->bloqueos();

            usort($this->filas, fn ($a, $b) => strcmp($a['fecha_hora'], $b['fecha_hora']));
            foreach (array_chunk($this->filas, 500) as $lote) {
                DB::table('bitacoras')->insert($lote);
            }
            $this->command?->info('Bitácora: '.count($this->filas).' registros nuevos.');
        });
    }

    // -------------------------------------------------------------- pacientes

    private function pacientes(): void
    {
        $filas = DB::table('pacientes as p')->join('personas as pe', 'pe.id', '=', 'p.persona_id')
            ->leftJoin('recepcionistas as r', 'r.id', '=', 'p.recepcionista_registra_id')
            ->leftJoin('usuarios as ur', 'ur.persona_id', '=', 'r.persona_id')
            ->leftJoin('usuarios as up', fn ($j) => $j->on('up.persona_id', '=', 'p.persona_id')->where('up.rol_id', Siembra::rolId('PACIENTE')))
            ->orderBy('p.id')
            ->get(['p.id', 'p.codigo', 'p.fecha_registro', 'ur.id as recepcion', 'up.id as cuenta', 'up.nombre_usuario', 'up.correo',
                'up.correo_verificado_en', 'pe.nombres', 'pe.apellido_paterno', 'pe.apellido_materno', 'pe.tipo_documento',
                'pe.numero_documento', 'pe.fecha_nacimiento', 'pe.sexo']);

        foreach ($filas as $p) {
            $alta = Siembra::desdeTimestamp($p->fecha_registro);
            $persona = ['nombres' => $p->nombres, 'apellido_paterno' => $p->apellido_paterno, 'apellido_materno' => $p->apellido_materno,
                'tipo_documento' => $p->tipo_documento, 'numero_documento' => $p->numero_documento,
                'fecha_nacimiento' => $p->fecha_nacimiento, 'sexo' => $p->sexo];

            if ($p->recepcion !== null) {
                $this->registrar('CREAR_PACIENTE', 'pacientes', (int) $p->recepcion, $alta, 'pacientes', $p->id, 'WEB', null,
                    ['paciente' => ['codigo' => $p->codigo, 'estado' => 'ACTIVO'], 'persona' => $persona]);
            } elseif ($p->cuenta !== null) {
                // CU12: el autorregistro no tiene sesión previa; el inicio de sesión llega después.
                $this->registrar('AUTORREGISTRO', 'seguridad', (int) $p->cuenta, $alta, 'usuarios', $p->cuenta, 'WEB', null,
                    ['nombre_usuario' => $p->nombre_usuario, 'correo' => $p->correo, 'paciente' => $p->codigo], true, false);
                if ($p->correo_verificado_en !== null) {
                    $this->registrar('VERIFICAR_CORREO', 'seguridad', (int) $p->cuenta, Siembra::desdeTimestamp($p->correo_verificado_en),
                        'usuarios', $p->cuenta, 'WEB', ['correo_verificado_en' => null], ['correo_verificado_en' => $p->correo_verificado_en], true, false);
                }
            }
        }
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
            ->whereIn('f.id', DB::table('historiales_cita')->select('ficha_nueva_id')->whereNotNull('ficha_nueva_id'))
            ->get(['f.id', 'f.fecha', 'f.hora_inicio', 'm.codigo as medico', 'pm.sexo', 'pm.apellido_paterno', 'e.nombre as especialidad'])
            ->keyBy('id');
        $citas = DB::table('citas as c')->join('pacientes as p', 'p.id', '=', 'c.paciente_id')
            ->get(['c.id', 'c.codigo', 'c.origen', 'c.cita_reemplazada_id', 'c.motivo_cancelacion', 'p.codigo as paciente'])->keyBy('id');

        $ficha = [];
        foreach (DB::table('historiales_cita')->orderBy('fecha_hora')->orderBy('id')->get() as $h) {
            $c = $citas[$h->cita_id];
            $cuando = Siembra::desdeTimestamp($h->fecha_hora);
            if ($h->ficha_nueva_id !== null) {
                $anterior = $ficha[$c->id] ?? null;
                $ficha[$c->id] = $fichas[$h->ficha_nueva_id];
            }
            $f = $ficha[$c->id];
            $foto = fn (string $estado, object $fi) => ['codigo' => $c->codigo, 'paciente' => $c->paciente, 'ficha_id' => $fi->id,
                'fecha' => $fi->fecha, 'hora_inicio' => substr($fi->hora_inicio, 0, 5), 'medico' => $fi->medico,
                'especialidad' => $fi->especialidad, 'origen' => $c->origen, 'estado' => $estado];

            [$accion, $antes, $despues, $voz] = match (true) {
                $h->estado_anterior === null => [$c->cita_reemplazada_id === null ? 'PROGRAMAR_CITA' : 'REASIGNAR_CITA', null, $foto('PROGRAMADA', $f),
                    'Quiero una cita de '.$f->especialidad.' con '.($f->sexo === 'F' ? 'la doctora ' : 'el doctor ').$f->apellido_paterno
                        .' para el '.$this->dia($f).' a las '.substr($f->hora_inicio, 0, 5)],
                $h->ficha_anterior_id !== null => ['REPROGRAMAR_CITA', $foto('PROGRAMADA', $anterior), $foto('PROGRAMADA', $f),
                    "Cambia mi cita {$c->codigo} para el ".$this->dia($f).' a las '.substr($f->hora_inicio, 0, 5)],
                $h->estado_nuevo === 'CANCELADA' => ['CANCELAR_CITA', $foto($h->estado_anterior, $f),
                    $foto('CANCELADA', $f) + ['motivo_cancelacion' => $c->motivo_cancelacion], "Cancela mi cita {$c->codigo}"],
                default => ['CAMBIO_ESTADO_CITA', $foto($h->estado_anterior, $f), $foto($h->estado_nuevo, $f), "Confirmo mi cita {$c->codigo}"],
            };
            $this->registrar($accion, 'citas', $h->usuario_realiza_id, $cuando, 'citas', $c->id, $h->canal, $antes, $despues,
                true, true, $h->canal === 'VOZ' ? $voz : null);
        }
    }

    private function dia(object $f): string
    {
        return CarbonImmutable::parse($f->fecha)->locale('es')->isoFormat('dddd D [de] MMMM');
    }

    // ---------------------------------------------------------------- clínica

    private function clinica(): void
    {
        $consultas = DB::table('consultas as co')->join('citas as c', 'c.id', '=', 'co.cita_id')
            ->join('fichas as f', 'f.id', '=', 'c.ficha_id')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('medicos as m', 'm.id', '=', 'me.medico_id')
            ->join('usuarios as u', 'u.persona_id', '=', 'm.persona_id')
            ->orderBy('co.id')
            ->get(['co.id', 'co.inicio', 'co.fin', 'co.estado', 'co.codigo_cie10', 'c.codigo', 'u.id as medico'])->keyBy('id');

        foreach ($consultas as $co) {
            if ($co->fin !== null) {
                $this->registrar('FINALIZAR_CONSULTA', 'consultas', (int) $co->medico, Siembra::desdeTimestamp($co->fin), 'consultas', $co->id, 'WEB',
                    ['estado' => 'EN_CURSO'], ['cita' => $co->codigo, 'codigo_cie10' => $co->codigo_cie10, 'estado' => 'FINALIZADA']);
            }
        }

        $prescripciones = DB::table('prescripciones')->selectRaw('tratamiento_id, count(*) as n')->groupBy('tratamiento_id')->pluck('n', 'tratamiento_id');
        foreach (DB::table('tratamientos')->orderBy('id')->get() as $t) {
            $co = $consultas[$t->consulta_id];
            $cuando = Siembra::desdeTimestamp($co->fin ?? $co->inicio)->subMinutes(2);
            $this->registrar('REGISTRAR_TRATAMIENTO', 'consultas', (int) $co->medico, $cuando, 'tratamientos', $t->id, 'WEB', null,
                ['consulta' => $co->codigo, 'descripcion' => $t->descripcion, 'prescripciones' => (int) ($prescripciones[$t->id] ?? 0)]);
        }

        $resultados = DB::table('resultados_laboratorio')
            ->selectRaw("orden_laboratorio_id, max(fecha_resultado) as fecha, min(usuario_carga_id) as usuario, count(*) as n,
                sum(case when interpretacion in ('CRITICO_BAJO','CRITICO_ALTO') then 1 else 0 end) as criticos")
            ->groupBy('orden_laboratorio_id')->get()->keyBy('orden_laboratorio_id');
        foreach (DB::table('ordenes_laboratorio')->orderBy('id')->get() as $o) {
            $medico = (int) $consultas[$o->consulta_id]->medico;
            $solicitud = Siembra::desdeTimestamp($o->fecha_solicitud);
            $this->registrar('SOLICITAR_EXAMEN', 'laboratorio', $medico, $solicitud, 'ordenes_laboratorio', $o->id, 'WEB', null,
                ['codigo' => $o->codigo, 'prioridad' => $o->prioridad, 'estado' => 'SOLICITADA']);
            if ($o->estado === 'ANULADA') {
                $this->registrar('ANULAR_ORDEN', 'laboratorio', $medico, $solicitud->addMinutes(30), 'ordenes_laboratorio', $o->id, 'WEB',
                    ['estado' => 'SOLICITADA'], ['estado' => 'ANULADA']);
            }
            if (($r = $resultados[$o->id] ?? null) !== null) {
                $this->registrar('CARGAR_RESULTADOS', 'laboratorio', (int) $r->usuario, Siembra::desdeTimestamp($r->fecha), 'ordenes_laboratorio', $o->id, 'WEB',
                    ['estado' => 'MUESTRA_TOMADA'], ['codigo' => $o->codigo, 'estado' => 'RESULTADO_LISTO', 'parametros' => (int) $r->n, 'criticos' => (int) $r->criticos]);
            }
            if ($o->fecha_revision !== null) {
                $this->registrar('REVISAR_RESULTADOS', 'laboratorio', $medico, Siembra::desdeTimestamp($o->fecha_revision), 'ordenes_laboratorio', $o->id, 'WEB',
                    ['estado' => 'RESULTADO_LISTO'], ['estado' => 'REVISADA']);
            }
        }
    }

    // --------------------------------------------------------------- sesiones

    /**
     * Un INICIO_SESION por usuario y día antes de su primera acción; a veces un
     * intento fallido justo antes, un CIERRE_SESION después de la última acción y,
     * en el primer día de cada cuenta del personal (y de algunos pacientes), el
     * cambio de la contraseña temporal.
     */
    private function sesiones(): void
    {
        $ahora = Siembra::ahora();
        $primerDia = [];
        ksort($this->jornadas);
        foreach ($this->jornadas as $usuario => $dias) {
            ksort($dias);
            foreach ($dias as $dia => [$primera, $ultima, $canal]) {
                $h = crc32("{$usuario}|{$dia}") % 100;
                $inicio = $primera->subMinutes(3 + $h % 20);
                if ($h < 5) {
                    $this->registrar('LOGIN_FALLIDO', 'seguridad', $usuario, $inicio->subMinute(), null, null, 'WEB', null, null, false, false);
                } elseif ($h < 7) {
                    // Nombre de usuario inexistente: la aplicación no puede asociarlo a una cuenta.
                    $this->registrar('LOGIN_FALLIDO', 'seguridad', null, $inicio->subMinutes(2), null, null, 'WEB', null, null, false, false);
                }
                $this->registrar('INICIO_SESION', 'seguridad', $usuario, $inicio, null, null, 'WEB', null, null, true, false);

                $cambia = ($this->roles[$usuario] ?? '') !== 'PACIENTE' || $usuario % 10 === 0;
                if (! isset($primerDia[$usuario]) && $cambia && $inicio->addMinutes(2)->lt($primera)) {
                    $this->registrar('CAMBIO_PASSWORD', 'seguridad', $usuario, $inicio->addMinutes(2), 'usuarios', $usuario, 'WEB', null, null, true, false);
                }
                $primerDia[$usuario] = true;

                $cierre = $ultima->addMinutes(2 + $h % 15);
                if ($h % 10 < 6 && $cierre->lte($ahora)) {
                    $this->registrar('CIERRE_SESION', 'seguridad', $usuario, $cierre, null, null, 'WEB', null, null, true, false);
                }
            }
        }
    }

    /** Cuentas bloqueadas por intentos fallidos (CU01): los intentos que llevaron al bloqueo. */
    private function bloqueos(): void
    {
        $minutos = (int) (DB::table('configuraciones_sistema')->where('clave', 'minutos_bloqueo_login')->value('valor') ?? 15);
        foreach (DB::table('usuarios')->whereNotNull('bloqueado_hasta')->where('intentos_fallidos', '>', 0)->orderBy('id')
            ->get(['id', 'intentos_fallidos', 'bloqueado_hasta']) as $u) {
            // La demo prolonga el bloqueo 14 días desde la siembra (PacienteDemoSeeder): los intentos se
            // ubican antes de ese instante, que está guardado y no depende del reloj de esta ejecución.
            $ultimo = Siembra::desdeTimestamp($u->bloqueado_hasta)->subDays(14)->subMinutes($minutos + 60);
            for ($i = (int) $u->intentos_fallidos; $i >= 1; $i--) {
                $bloquea = $i === 1;
                $this->registrar('LOGIN_FALLIDO', 'seguridad', (int) $u->id, $ultimo->subMinutes($i - 1), $bloquea ? 'usuarios' : null,
                    $bloquea ? $u->id : null, 'WEB', null, $bloquea ? ['bloqueado_hasta' => $u->bloqueado_hasta] : null, false, false);
            }
        }
    }

    // ----------------------------------------------------------------- común

    private function clave(string $accion, int|string|null $usuario, ?string $tabla, int|string|null $registro, string $fecha): string
    {
        return implode('|', [$accion, $usuario, $tabla, $registro, substr($fecha, 0, 19)]);
    }

    /**
     * Agrega un registro si no existe. Si $sesion es true, la acción cuenta para
     * la jornada del usuario (su inicio y cierre de sesión de ese día).
     */
    private function registrar(string $accion, string $modulo, int|string|null $usuario, CarbonImmutable $cuando, ?string $tabla, int|string|null $registro,
        string $canal, ?array $antes, ?array $despues, bool $exito = true, bool $sesion = true, ?string $voz = null): void
    {
        $usuario = $usuario === null ? null : (int) $usuario;
        $registro = $registro === null ? null : (int) $registro;
        if ($sesion && $usuario !== null && $canal !== 'SISTEMA') {
            $dia = $cuando->toDateString();
            $j = $this->jornadas[$usuario][$dia] ?? [$cuando, $cuando, $canal];
            $this->jornadas[$usuario][$dia] = [$j[0]->min($cuando), $j[1]->max($cuando), $canal];
        }

        $ts = Siembra::ts($cuando);
        $clave = $this->clave($accion, $usuario, $tabla, $registro, $ts);
        if (isset($this->existentes[$clave])) {
            return;
        }
        $this->existentes[$clave] = true;

        $json = fn (?array $d) => $d === null ? null : json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->filas[] = [
            'usuario_id' => $usuario,
            'accion' => $accion,
            'modulo' => $modulo,
            'tabla_afectada' => $tabla,
            'registro_id' => $registro,
            'canal' => $canal,
            'transcripcion_voz' => $canal === 'VOZ' ? $voz : null,
            'datos_anteriores' => $json($antes),
            'datos_nuevos' => $json($despues),
            'exito' => $exito,
            'ip' => $this->ip($usuario, $canal),
            'fecha_hora' => $ts,
        ];
    }

    /** IP de origen: red interna para el personal, pública para los pacientes, local para el sistema. */
    private function ip(?int $usuario, string $canal): string
    {
        if ($canal === 'SISTEMA') {
            return '127.0.0.1';
        }
        if ($usuario === null) {
            return '190.104.'.(crc32((string) $canal) % 200 + 10).'.77';
        }

        return ($this->roles[$usuario] ?? '') === 'PACIENTE'
            ? '181.115.'.($usuario * 7 % 250 + 1).'.'.($usuario * 13 % 250 + 1)
            : '10.0.0.'.($usuario % 200 + 20);
    }
}
