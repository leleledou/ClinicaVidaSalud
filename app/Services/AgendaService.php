<?php

namespace App\Services;

use App\Models\BloqueoAgenda;
use App\Models\ConfiguracionSistema;
use App\Models\Ficha;
use App\Models\HorarioAtencion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de la agenda (RF-11): generación de fichas y cruces de horarios.
 * La usan CU09 Gestionar horarios, CU25 Gestionar bloqueos de agenda y, más adelante, CU14, CU15 y el Temporizador.
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

    // CU25 paso 2: fichas BLOQUEADAS que cruza un bloqueo (mismo criterio que SQL_MOTIVO_BLOQUEO), como
    // subconsulta sin parámetros sobre bloqueos_agenda.
    public const SQL_FICHAS_BLOQUEADAS = "(select count(*) from fichas f
        join horarios_atencion h on h.id = f.horario_atencion_id
        join medicos_especialidades me on me.id = h.medico_especialidad_id
        where f.estado = 'BLOQUEADA'
        and bloqueos_agenda.inicio < (f.fecha + f.hora_fin) and bloqueos_agenda.fin > (f.fecha + f.hora_inicio)
        and (bloqueos_agenda.alcance = 'CLINICA' or (bloqueos_agenda.alcance = 'MEDICO' and bloqueos_agenda.medico_id = me.medico_id)
            or (bloqueos_agenda.alcance = 'CONSULTORIO' and bloqueos_agenda.consultorio_id = h.consultorio_id)))";

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
     * RF-11: calcula y guarda las fichas (los horarios disponibles) de un horario entre dos fechas.
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
     * CU25: fichas del alcance de un bloqueo cuyo intervalo cruza [desde, hasta) (mismo criterio que
     * bloqueoQueCruza: inicio del bloqueo < fin de la ficha y fin del bloqueo > inicio de la ficha).
     * CLINICA: todas; CONSULTORIO: horarios de ese consultorio; MEDICO: horarios del médico en
     * cualquier especialidad. Alias h (horarios_atencion) y me (medicos_especialidades).
     */
    public function fichasDelAlcance(string $alcance, ?int $medicoId, ?int $consultorioId, Carbon $desde, Carbon $hasta): Builder
    {
        return Ficha::join("horarios_atencion as h", "h.id", "=", "fichas.horario_atencion_id")
            ->join("medicos_especialidades as me", "me.id", "=", "h.medico_especialidad_id")
            // Rango de fechas (usa ix_fichas_fecha_hora) y cruce exacto con la hora.
            ->whereBetween("fichas.fecha", [$desde->toDateString(), $hasta->toDateString()])
            ->whereRaw("(fichas.fecha + fichas.hora_fin) > ?::timestamp", [$desde->format("Y-m-d H:i:s")])
            ->whereRaw("(fichas.fecha + fichas.hora_inicio) < ?::timestamp", [$hasta->format("Y-m-d H:i:s")])
            ->when($alcance === "CONSULTORIO", fn ($consulta) => $consulta->where("h.consultorio_id", $consultorioId))
            ->when($alcance === "MEDICO", fn ($consulta) => $consulta->where("me.medico_id", $medicoId));
    }

    // CU25 paso 6: fichas afectadas = las del alcance que cruzan el bloqueo y cuya hora de inicio todavía no pasó.
    public function fichasAfectadas(string $alcance, ?int $medicoId, ?int $consultorioId, Carbon $inicio, Carbon $fin, Carbon $ahora): Builder
    {
        return $this->fichasDelAlcance($alcance, $medicoId, $consultorioId, $inicio, $fin)
            ->whereRaw("(fichas.fecha + fichas.hora_inicio) > ?::timestamp", [$ahora->format("Y-m-d H:i:s")]);
    }

    /**
     * CU25 A2: de unas fichas BLOQUEADAS (id, horario_atencion_id, fecha, hora_inicio, hora_fin), los id
     * de las que pueden volver a HABILITADA entre $desde y $hasta: las que no cruza ningún bloqueo
     * vigente (bloqueosDe / bloqueoQueCruza) y cuyo horario sigue ACTIVO y vigente en esa fecha (CU09 A1
     * bloquea las fichas posteriores al cierre de un horario).
     */
    public function fichasQueSeHabilitan($fichas, Carbon $desde, Carbon $hasta): array
    {
        $habilitar = [];
        foreach ($fichas->groupBy("horario_atencion_id") as $horarioId => $grupo) {
            $horario = HorarioAtencion::with("medicoEspecialidad")->find($horarioId);
            $bloqueos = $this->bloqueosDe($horario, $desde, $hasta);

            foreach ($grupo as $ficha) {
                $fecha = Carbon::parse($ficha->fecha)->toDateString();
                $vigente = $horario->estado === "ACTIVO"
                    && $fecha >= Carbon::parse($horario->vigente_desde)->toDateString()
                    && ($horario->vigente_hasta === null || $fecha <= Carbon::parse($horario->vigente_hasta)->toDateString());
                $inicio = Carbon::parse($fecha)->setTimeFromTimeString($ficha->hora_inicio);
                $fin = Carbon::parse($fecha)->setTimeFromTimeString($ficha->hora_fin);

                if ($vigente && ! $this->bloqueoQueCruza($bloqueos, $inicio, $fin)) {
                    $habilitar[] = $ficha->id;
                }
            }
        }

        return $habilitar;
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
