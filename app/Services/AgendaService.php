<?php

namespace App\Services;

use App\Models\BloqueoAgenda;
use App\Models\ConfiguracionSistema;
use App\Models\HorarioAtencion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de la agenda (RF05): generación de fichas y cruces de horarios.
 * La usan CU09 Gestionar horarios y, más adelante, CU14, CU15 y el Temporizador.
 */
class AgendaService
{
    // Día ISO (1 = lunes ... 7 = domingo), como horarios_atencion.dia_semana.
    public const DIAS = [1 => "Lunes", 2 => "Martes", 3 => "Miércoles", 4 => "Jueves", 5 => "Viernes", 6 => "Sábado", 7 => "Domingo"];

    // CU14 paso 4 / CU19 paso 4: motivo del bloqueo de agenda (clínica, médico o consultorio) que cruza
    // la ficha. Subconsulta sin parámetros sobre los alias fichas, me (medicos_especialidades) y h (horarios_atencion).
    public const SQL_MOTIVO_BLOQUEO = "(select b.motivo from bloqueos_agenda b where b.inicio < (fichas.fecha + fichas.hora_fin) and b.fin > (fichas.fecha + fichas.hora_inicio)
        and (b.alcance = 'CLINICA' or (b.alcance = 'MEDICO' and b.medico_id = me.medico_id) or (b.alcance = 'CONSULTORIO' and b.consultorio_id = h.consultorio_id))
        order by b.inicio limit 1)";

    /**
     * CU15/CU16 (días de atención del médico) y CU19 excepción: horarios ACTIVOS y vigentes desde hoy,
     * de un médico o de todos, con su especialidad, ordenados por día y hora (consulta parametrizada).
     */
    public function horariosVigentes(?int $medicoId = null)
    {
        return HorarioAtencion::join("medicos_especialidades as me", "me.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("especialidades", "especialidades.id", "=", "me.especialidad_id")
            ->where("horarios_atencion.estado", "ACTIVO")
            ->where(fn ($vigencia) => $vigencia->whereNull("horarios_atencion.vigente_hasta")->orWhere("horarios_atencion.vigente_hasta", ">=", today()->toDateString()))
            ->when($medicoId, fn ($consulta) => $consulta->where("me.medico_id", $medicoId))
            ->orderBy("horarios_atencion.dia_semana")
            ->orderBy("horarios_atencion.hora_inicio")
            ->get(["horarios_atencion.dia_semana", "horarios_atencion.hora_inicio", "horarios_atencion.hora_fin",
                "me.medico_id", "me.especialidad_id", "especialidades.nombre as especialidad"]);
    }

    // Texto de los días y horas de unos horarios: «lunes, miércoles y viernes de 08:00 a 12:00; martes de 14:00 a 18:00».
    public static function textoAtencion($horarios): string
    {
        return $horarios->groupBy(fn ($horario) => substr($horario->hora_inicio, 0, 5) . " a " . substr($horario->hora_fin, 0, 5))
            ->map(function ($grupo, $horas) {
                $dias = $grupo->pluck("dia_semana")->map(fn ($dia) => (int) $dia)->unique()->sort()
                    ->map(fn ($dia) => mb_strtolower(self::DIAS[$dia]))->values()->all();
                $ultimo = array_pop($dias);

                return ($dias ? implode(", ", $dias) . " y " : "") . $ultimo . " de " . $horas;
            })
            ->implode("; ");
    }

    // Semanas por delante para las que se generan fichas (configuraciones_sistema).
    public function semanasGeneracion(): int
    {
        return (int) ConfiguracionSistema::find("semanas_generacion_fichas")->valor;
    }

    /**
     * RF05: calcula y guarda las fichas (los horarios disponibles) de un horario entre dos fechas.
     * Solo fechas dentro de la vigencia, desde hoy, del día de la semana del horario y con inicio
     * futuro (RN06). Una ficha que se cruza con un bloqueo de agenda (feriado, cierre del
     * consultorio o ausencia del médico) queda BLOQUEADA. Es idempotente: devuelve cuántas se
     * insertaron realmente (ux_fichas_horario_fecha_hora evita repetirlas).
     */
    public function generarFichas(HorarioAtencion $horario, Carbon $desde, Carbon $hasta): int
    {
        $horario->loadMissing("medicoEspecialidad");

        // Fechas: entre max(desde, vigente_desde, hoy) y min(hasta, vigente_hasta).
        $inicio = max($desde->copy()->startOfDay(), Carbon::parse($horario->vigente_desde)->startOfDay(), today());
        $fin = $hasta->copy()->startOfDay();
        if ($horario->vigente_hasta !== null) {
            $fin = min($fin, Carbon::parse($horario->vigente_hasta)->startOfDay());
        }
        if ($inicio->greaterThan($fin)) {
            return 0;
        }

        // Bloqueos que pueden afectar a este horario dentro del rango de fechas.
        $bloqueos = $this->bloqueosDe($horario, $inicio, $fin->copy()->endOfDay());

        // Primera fecha con el día de la semana del horario.
        $fecha = $inicio->copy();
        while ($fecha->dayOfWeekIso !== (int) $horario->dia_semana) {
            $fecha->addDay();
        }

        $ahora = now();
        $filas = [];
        for (; $fecha->lessThanOrEqualTo($fin); $fecha->addWeek()) {
            $finJornada = $fecha->copy()->setTimeFromTimeString($horario->hora_fin);
            $ficha = $fecha->copy()->setTimeFromTimeString($horario->hora_inicio);

            // De duracion_ficha_min en duracion_ficha_min, sin pasarse de la hora final (RN08).
            while ($ficha->copy()->addMinutes($horario->duracion_ficha_min)->lessThanOrEqualTo($finJornada)) {
                $finFicha = $ficha->copy()->addMinutes($horario->duracion_ficha_min);

                // RN06: no se crean fichas cuyo inicio ya pasó.
                if ($ficha->greaterThan($ahora)) {
                    $filas[] = [
                        "horario_atencion_id" => $horario->id,
                        "fecha" => $fecha->toDateString(),
                        "hora_inicio" => $ficha->format("H:i:s"),
                        "hora_fin" => $finFicha->format("H:i:s"),
                        "estado" => $this->bloqueoQueCruza($bloqueos, $ficha, $finFicha) ? "BLOQUEADA" : "HABILITADA",
                    ];
                }

                $ficha = $finFicha;
            }
        }

        // insertOrIgnore: las fichas que ya existen no se repiten; devuelve las insertadas.
        $insertadas = 0;
        foreach (array_chunk($filas, 500) as $lote) {
            $insertadas += DB::table("fichas")->insertOrIgnore($lote);
        }

        return $insertadas;
    }

    /**
     * Bloqueos de agenda que afectan a un horario entre dos instantes: los de la CLINICA, los del
     * CONSULTORIO del horario y los del MEDICO del horario (consulta parametrizada).
     */
    public function bloqueosDe(HorarioAtencion $horario, Carbon $desde, Carbon $hasta)
    {
        $horario->loadMissing("medicoEspecialidad");
        $medicoId = $horario->medicoEspecialidad->medico_id;

        return BloqueoAgenda::where("inicio", "<", $hasta)
            ->where("fin", ">", $desde)
            ->where(function ($consulta) use ($horario, $medicoId) {
                $consulta->where("alcance", "CLINICA")
                    ->orWhere(fn ($medico) => $medico->where("alcance", "MEDICO")->where("medico_id", $medicoId));
                if ($horario->consultorio_id !== null) {
                    $consulta->orWhere(fn ($consultorio) => $consultorio->where("alcance", "CONSULTORIO")->where("consultorio_id", $horario->consultorio_id));
                }
            })
            ->orderBy("inicio")
            ->get();
    }

    // El primer bloqueo cuyo intervalo [inicio, fin] se cruza con el de la ficha (o null).
    public function bloqueoQueCruza($bloqueos, Carbon $inicioFicha, Carbon $finFicha): ?BloqueoAgenda
    {
        return $bloqueos->first(fn ($bloqueo) => Carbon::parse($bloqueo->inicio)->lessThan($finFicha) && Carbon::parse($bloqueo->fin)->greaterThan($inicioFicha));
    }

    /**
     * RN07: horario ACTIVO del mismo médico que se cruza (mismo día, vigencias y horas que se
     * cruzan). Devuelve el horario con el nombre de su especialidad, o null.
     */
    public function haySolapeMedico(int $medicoId, int $dia, string $horaInicio, string $horaFin, string $desde, ?string $hasta, ?int $ignorarId = null): ?HorarioAtencion
    {
        return $this->horariosQueSeCruzan($dia, $horaInicio, $horaFin, $desde, $hasta, $ignorarId)
            ->where("medicos_especialidades.medico_id", $medicoId)
            ->first();
    }

    /**
     * Horario ACTIVO en el mismo consultorio que se cruza (lo mismo que ex_horarios_consultorio).
     * Devuelve el horario con el nombre del consultorio y del médico, o null.
     */
    public function haySolapeConsultorio(int $consultorioId, int $dia, string $horaInicio, string $horaFin, string $desde, ?string $hasta, ?int $ignorarId = null): ?HorarioAtencion
    {
        return $this->horariosQueSeCruzan($dia, $horaInicio, $horaFin, $desde, $hasta, $ignorarId)
            ->where("horarios_atencion.consultorio_id", $consultorioId)
            ->first();
    }

    // Consulta común: horarios ACTIVOS del día cuyas vigencias y horas se cruzan con las dadas.
    // Las horas se tocan sin cruzarse (12:00 termina uno y empieza otro), como en la base.
    private function horariosQueSeCruzan(int $dia, string $horaInicio, string $horaFin, string $desde, ?string $hasta, ?int $ignorarId)
    {
        return HorarioAtencion::join("medicos_especialidades", "medicos_especialidades.id", "=", "horarios_atencion.medico_especialidad_id")
            ->join("especialidades", "especialidades.id", "=", "medicos_especialidades.especialidad_id")
            ->join("medicos", "medicos.id", "=", "medicos_especialidades.medico_id")
            ->join("personas", "personas.id", "=", "medicos.persona_id")
            ->leftJoin("consultorios", "consultorios.id", "=", "horarios_atencion.consultorio_id")
            ->where("horarios_atencion.estado", "ACTIVO")
            ->where("horarios_atencion.dia_semana", $dia)
            ->whereRaw("daterange(horarios_atencion.vigente_desde, horarios_atencion.vigente_hasta, '[]') && daterange(?::date, ?::date, '[]')", [$desde, $hasta])
            ->whereRaw("horarios_atencion.hora_inicio < ?::time AND ?::time < horarios_atencion.hora_fin", [$horaFin, $horaInicio])
            ->when($ignorarId, fn ($consulta) => $consulta->where("horarios_atencion.id", "<>", $ignorarId))
            ->select("horarios_atencion.*", "especialidades.nombre as especialidad", "consultorios.nombre as consultorio",
                "medicos.codigo as medico_codigo", "personas.nombres as medico_nombres", "personas.apellido_paterno as medico_apellido")
            ->orderBy("horarios_atencion.hora_inicio");
    }
}
