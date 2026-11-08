<?php

namespace App\Services;

use App\Models\Bitacora;
use App\Models\Cita;
use App\Models\Especialidad;
use App\Models\Medico;
use App\Models\Usuario;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;

/**
 * CU40 Generar reportes (RF-41): citas, pacientes, atenciones y agenda con filtros personalizables (filtros,
 * columnas y orden). Cada reporte se arma con UNA consulta (consulta()) que usan igual la pantalla, el PDF y el
 * CSV; los totales salen de la misma base filtrada. Solo lectura, con parámetros; la bitácora registra cada
 * generación y exportación (módulo «reportes»).
 */
class ReporteService
{
    public const TIPOS = ["citas" => "Citas", "pacientes" => "Pacientes", "atenciones" => "Atenciones", "agenda" => "Agenda y ocupación"];

    public const DESCRIPCIONES = [
        "citas" => "Citas por fecha, especialidad, médico, estado, origen y canal, con totales por estado.",
        "pacientes" => "Pacientes por estado, sexo, edad, seguro vigente y fecha de registro, con totales por sexo y edad.",
        "atenciones" => "Consultas finalizadas por fecha, médico, especialidad y CIE-10, con el top 10 de diagnósticos.",
        "agenda" => "Fichas por día y médico: disponibles, ocupadas, bloqueadas y porcentaje de ocupación.",
    ];

    // CU40 pasos 3 y 4: filas por página en pantalla y máximo de filas del PDF.
    public const POR_PAGINA = 25;
    public const MAX_PDF = 2000;

    public const FORMATOS = ["pdf" => "PDF", "csv" => "Excel/CSV"];

    private const GRUPOS_EDAD = "CASE WHEN %1\$s < 18 THEN '0 a 17' WHEN %1\$s < 40 THEN '18 a 39' WHEN %1\$s < 60 THEN '40 a 59' ELSE '60 o más' END";

    /**
     * CU40 paso 2: columnas de cada reporte (clave => [título, expresión SQL]). El usuario elige cuáles incluir y
     * por cuál ordenar; solo se aceptan las claves de esta lista (nunca SQL del usuario).
     */
    public function columnas(string $tipo): array
    {
        $paciente = "pp.nombres || ' ' || pp.apellido_paterno || coalesce(' ' || pp.apellido_materno, '')";
        $medico = "pm.nombres || ' ' || pm.apellido_paterno || coalesce(' ' || pm.apellido_materno, '')";

        return match ($tipo) {
            "citas" => [
                "codigo" => ["Código", "c.codigo"], "fecha" => ["Fecha", "f.fecha"], "hora" => ["Hora", "f.hora_inicio"],
                "paciente" => ["Paciente", $paciente], "medico" => ["Médico", $medico], "especialidad" => ["Especialidad", "e.nombre"],
                "estado" => ["Estado", "c.estado"], "origen" => ["Origen", "c.origen"], "canal" => ["Canal", "c.canal"],
            ],
            "pacientes" => [
                "codigo" => ["Código", "p.codigo"], "nombre" => ["Nombre", $paciente],
                "documento" => ["Documento", "pp.tipo_documento || ' ' || pp.numero_documento || coalesce('-' || pp.complemento, '')"],
                "edad" => ["Edad", $this->edadSql()], "sexo" => ["Sexo", "pp.sexo"], "telefono" => ["Teléfono", "pp.telefono"],
                "seguro" => ["Seguro vigente", "(" . $this->seguroSql() . ")"], "fecha_registro" => ["Registro", "p.fecha_registro"],
            ],
            "atenciones" => [
                "fecha" => ["Fecha", "co.inicio"], "paciente" => ["Paciente", $paciente], "medico" => ["Médico", $medico],
                "especialidad" => ["Especialidad", "e.nombre"], "cie10" => ["CIE-10", "co.codigo_cie10"], "diagnostico" => ["Diagnóstico", "co.diagnostico"],
            ],
            "agenda" => [
                "fecha" => ["Fecha", "f.fecha"], "dia" => ["Día", "extract(isodow from f.fecha)"], "medico" => ["Médico", $medico],
                "disponibles" => ["Disponibles", "disponibles"], "ocupadas" => ["Ocupadas", "ocupadas"], "bloqueadas" => ["Bloqueadas", "bloqueadas"],
                "total" => ["Total", "total"], "ocupacion" => ["Ocupación", "ocupacion"],
            ],
        };
    }

    /**
     * CU40 paso 2 / A1: filtros validados con los valores por defecto (el mes actual en citas, atenciones y
     * agenda). Si un filtro no es válido, se informa y se usan los valores por defecto. Devuelve [filtros, errores].
     */
    public function filtros(string $tipo, array $entrada): array
    {
        $columnas = array_keys($this->columnas($tipo));
        $porDefecto = $this->porDefecto($tipo);
        $validador = Validator::make($entrada, $this->reglas($tipo, $columnas), $this->mensajes());
        if ($validador->fails()) {
            return [$porDefecto, $validador->errors()];
        }

        $f = array_merge($porDefecto, array_filter($validador->validated(), fn ($v) => $v !== null && $v !== "" && $v !== []));
        // Las columnas elegidas en el orden de la lista (al menos una).
        $f["columnas"] = array_values(array_intersect($columnas, (array) $f["columnas"]));

        return [$f, new MessageBag()];
    }

    /**
     * CU40 paso 2: LA consulta del reporte (pantalla paginada, PDF con límite y CSV en streaming), con los
     * filtros y el orden elegidos y un desempate estable. Trae todas las columnas; cada salida muestra las elegidas.
     */
    public function consulta(string $tipo, array $f): Builder
    {
        $columnas = $this->columnas($tipo);
        $consulta = $this->base($tipo, $f);
        $paciente = $columnas[$tipo === "pacientes" ? "nombre" : ($tipo === "agenda" ? "medico" : "paciente")][1];

        $consulta = match ($tipo) {
            "citas" => $consulta->selectRaw("c.id, c.codigo, f.fecha, f.hora_inicio as hora, {$paciente} as paciente, {$columnas['medico'][1]} as medico,
                e.nombre as especialidad, c.estado, c.origen, c.canal"),
            "pacientes" => $consulta->selectRaw("p.id, p.codigo, {$paciente} as nombre, {$columnas['documento'][1]} as documento,
                {$this->edadSql()} as edad, pp.sexo, pp.telefono, ({$this->seguroSql()}) as seguro, p.fecha_registro", [$this->hoy(), $this->hoy(), $this->hoy()]),
            "atenciones" => $consulta->selectRaw("co.id, co.inicio as fecha, {$columnas['paciente'][1]} as paciente, {$columnas['medico'][1]} as medico,
                e.nombre as especialidad, co.codigo_cie10 as cie10, co.diagnostico"),
            "agenda" => $consulta->selectRaw("f.fecha, extract(isodow from f.fecha) as dia, m.id as medico_id, {$paciente} as medico, " . $this->agregadosAgenda())
                ->groupBy("f.fecha", "m.id", "pm.id"),
        };
        // Orden por el nombre de la columna de salida (validado contra la lista de columnas) y desempate estable.
        return $consulta->orderByRaw("{$f['orden']} " . ($f["direccion"] === "desc" ? "desc" : "asc") . " NULLS LAST")
            ->orderByRaw(match ($tipo) { "citas" => "c.id", "pacientes" => "p.id", "atenciones" => "co.id", "agenda" => "m.id" });
    }

    /**
     * CU40 paso 2: totales de la misma base filtrada: citas por estado; pacientes por sexo y grupo de edad;
     * atenciones por médico y los 10 CIE-10 más frecuentes; agenda, la suma de fichas y la ocupación.
     * Devuelve [título => [etiqueta => valor]].
     */
    public function totales(string $tipo, array $f): array
    {
        $contar = fn (string $expresion, array $bindings = [], ?int $limite = null, string $orden = "n desc, etiqueta") => $this->base($tipo, $f)
            ->selectRaw("{$expresion} as etiqueta, count(*) as n", $bindings)
            // Por el alias: con parámetros, PostgreSQL no reconoce como iguales la expresión del SELECT y la del GROUP BY.
            ->groupByRaw("etiqueta")->orderByRaw($orden)
            ->when($limite, fn ($c) => $c->limit($limite))
            ->pluck("n", "etiqueta")->all();

        return match ($tipo) {
            "citas" => ["Total de citas" => ["Citas" => array_sum($t = $contar("c.estado"))], "Por estado" => $t],
            "pacientes" => [
                "Total de pacientes" => ["Pacientes" => array_sum($t = $contar("pp.sexo"))],
                "Por sexo" => collect($t)->mapWithKeys(fn ($n, $s) => [$s === "F" ? "Femenino" : ($s === "M" ? "Masculino" : "Sin dato") => $n])->all(),
                // La plantilla usa la edad tres veces: un parámetro (hoy) por cada una.
                "Por grupo de edad" => $contar(sprintf(self::GRUPOS_EDAD, $this->edadSql()), array_fill(0, 3, $this->hoy()), null, "etiqueta"),
            ],
            "atenciones" => [
                "Total de atenciones" => ["Consultas finalizadas" => array_sum($t = $contar("pm.nombres || ' ' || pm.apellido_paterno"))],
                "Por médico" => $t,
                "Los 10 CIE-10 más frecuentes" => $contar("coalesce(co.codigo_cie10, 'Sin código')", [], 10),
            ],
            "agenda" => ["Fichas del periodo" => (function () use ($f) {
                $s = $this->base("agenda", $f)->selectRaw($this->agregadosAgenda())->first();

                return ["Disponibles" => (int) $s->disponibles, "Ocupadas" => (int) $s->ocupadas, "Bloqueadas" => (int) $s->bloqueadas,
                    "Total" => (int) $s->total, "Ocupación" => $s->ocupacion === null ? "—" : $this->numero($s->ocupacion) . " %"];
            })()],
        };
    }

    // CU40 pasos 3 y 4: una fila con las columnas elegidas, ya formateadas (pantalla, PDF y CSV).
    public function fila(string $tipo, object $registro, array $columnas): array
    {
        return array_map(fn ($clave) => match (true) {
            in_array($clave, ["fecha", "fecha_registro"], true) && $registro->{$clave} !== null => Carbon::parse($registro->{$clave})->format($tipo === "atenciones" ? "d/m/Y H:i" : "d/m/Y"),
            $clave === "hora" => substr((string) $registro->hora, 0, 5),
            $clave === "dia" => AgendaService::DIAS[(int) $registro->dia] ?? "",
            $clave === "ocupacion" => $registro->ocupacion === null ? "—" : $this->numero($registro->ocupacion) . " %",
            $clave === "seguro" => $registro->seguro ?? "Sin seguro",
            default => (string) ($registro->{$clave} ?? ""),
        }, $columnas);
    }

    // CU40 paso 4: filtros aplicados en texto (encabezado del PDF y bitácora).
    public function textoFiltros(string $tipo, array $f): array
    {
        $etiquetas = [
            "desde" => "Desde", "hasta" => "Hasta", "especialidad_id" => "Especialidad", "medico_id" => "Médico", "estado" => "Estado",
            "origen" => "Origen", "canal" => "Canal", "sexo" => "Sexo", "edad_min" => "Edad mínima", "edad_max" => "Edad máxima",
            "seguro" => "Seguro vigente", "cie10" => "CIE-10",
        ];
        $texto = [];
        foreach ($etiquetas as $clave => $etiqueta) {
            if (! isset($f[$clave])) {
                continue;
            }
            $texto[$etiqueta] = match ($clave) {
                "desde", "hasta" => Carbon::parse($f[$clave])->format("d/m/Y"),
                "especialidad_id" => Especialidad::find($f[$clave])?->nombre,
                "medico_id" => ($m = Medico::with("persona")->find($f[$clave])) ? CitaService::tituloMedico($m->persona) : null,
                "sexo" => $f[$clave] === "F" ? "Femenino" : "Masculino",
                "seguro" => $f[$clave] === "CON" ? "Con seguro" : "Sin seguro",
                default => (string) $f[$clave],
            };
        }
        $titulos = $this->columnas($tipo);
        $texto["Orden"] = $titulos[$f["orden"]][0] . ($f["direccion"] === "desc" ? " (descendente)" : " (ascendente)");

        return array_filter($texto, fn ($v) => $v !== null);
    }

    // CU40 paso 5: GENERAR_REPORTE (pantalla) o EXPORTAR_REPORTE (con el formato), módulo «reportes».
    public function registrar(string $accion, string $tipo, array $f, Usuario $usuario, array $extra = []): void
    {
        DB::transaction(fn () => Bitacora::registrar($accion, "reportes", $usuario->id, true, null, null, "WEB", null,
            ["reporte" => $tipo, "filtros" => $this->textoFiltros($tipo, $f), "columnas" => $f["columnas"]] + $extra));
    }

    // Opciones de los filtros (pantalla).
    public function opciones(): array
    {
        return [
            "especialidades" => Especialidad::orderBy("nombre")->pluck("nombre", "id")->all(),
            "medicos" => Medico::join("personas", "personas.id", "=", "medicos.persona_id")->orderBy("personas.apellido_paterno")->orderBy("personas.nombres")
                ->get(["medicos.id", "personas.nombres", "personas.apellido_paterno", "personas.sexo"])
                ->mapWithKeys(fn ($m) => [$m->id => ($m->sexo === "F" ? "Dra. " : "Dr. ") . "{$m->nombres} {$m->apellido_paterno}"])->all(),
            "estados" => Cita::ESTADOS,
            "origenes" => [CitaService::ORIGEN_RECEPCION, CitaService::ORIGEN_AUTOSERVICIO],
            "canales" => ["WEB", "VOZ"],
        ];
    }

    // CU40 paso 2: tablas y filtros de cada reporte (sin select ni orden: la comparten consulta() y totales()).
    private function base(string $tipo, array $f): Builder
    {
        $desde = $f["desde"] ?? null;
        $hasta = $f["hasta"] ?? null;

        return match ($tipo) {
            "citas" => $this->conMedico(DB::table("citas as c")->join("fichas as f", "f.id", "=", "c.ficha_id"), "f")
                ->join("pacientes as p", "p.id", "=", "c.paciente_id")->join("personas as pp", "pp.id", "=", "p.persona_id")
                ->when($desde, fn ($c) => $c->where("f.fecha", ">=", $desde))->when($hasta, fn ($c) => $c->where("f.fecha", "<=", $hasta))
                ->when($f["especialidad_id"] ?? null, fn ($c, $v) => $c->where("me.especialidad_id", $v))
                ->when($f["medico_id"] ?? null, fn ($c, $v) => $c->where("me.medico_id", $v))
                ->when($f["estado"] ?? null, fn ($c, $v) => $c->where("c.estado", $v))
                ->when($f["origen"] ?? null, fn ($c, $v) => $c->where("c.origen", $v))
                ->when($f["canal"] ?? null, fn ($c, $v) => $c->where("c.canal", $v)),
            "pacientes" => DB::table("pacientes as p")->join("personas as pp", "pp.id", "=", "p.persona_id")
                ->when($f["estado"] ?? null, fn ($c, $v) => $c->where("p.estado", $v))
                ->when($f["sexo"] ?? null, fn ($c, $v) => $c->where("pp.sexo", $v))
                ->when(isset($f["edad_min"]), fn ($c) => $c->whereRaw($this->edadSql() . " >= ?", [$this->hoy(), $f["edad_min"]]))
                ->when(isset($f["edad_max"]), fn ($c) => $c->whereRaw($this->edadSql() . " <= ?", [$this->hoy(), $f["edad_max"]]))
                ->when(($f["seguro"] ?? null) === "CON", fn ($c) => $c->whereRaw("exists (" . $this->seguroSql() . ")", [$this->hoy(), $this->hoy()]))
                ->when(($f["seguro"] ?? null) === "SIN", fn ($c) => $c->whereRaw("not exists (" . $this->seguroSql() . ")", [$this->hoy(), $this->hoy()]))
                ->when($desde, fn ($c) => $c->whereDate("p.fecha_registro", ">=", $desde))->when($hasta, fn ($c) => $c->whereDate("p.fecha_registro", "<=", $hasta)),
            "atenciones" => $this->conMedico(DB::table("consultas as co")->join("citas as c", "c.id", "=", "co.cita_id")->join("fichas as f", "f.id", "=", "c.ficha_id"), "f")
                ->join("pacientes as p", "p.id", "=", "c.paciente_id")->join("personas as pp", "pp.id", "=", "p.persona_id")
                ->where("co.estado", "FINALIZADA")
                ->when($desde, fn ($c) => $c->whereDate("co.inicio", ">=", $desde))->when($hasta, fn ($c) => $c->whereDate("co.inicio", "<=", $hasta))
                ->when($f["especialidad_id"] ?? null, fn ($c, $v) => $c->where("me.especialidad_id", $v))
                ->when($f["medico_id"] ?? null, fn ($c, $v) => $c->where("me.medico_id", $v))
                ->when($f["cie10"] ?? null, fn ($c, $v) => $c->whereRaw("upper(co.codigo_cie10) LIKE ?", [mb_strtoupper($v) . "%"])),
            // R-OCUP: una ficha ocupada tiene una cita activa (a lo sumo una: ux_citas_ficha_activa).
            "agenda" => $this->conMedico(DB::table("fichas as f"), "f")
                ->leftJoin("citas as ca", fn ($j) => $j->on("ca.ficha_id", "=", "f.id")->where("ca.estado", "<>", "CANCELADA"))
                ->when($desde, fn ($c) => $c->where("f.fecha", ">=", $desde))->when($hasta, fn ($c) => $c->where("f.fecha", "<=", $hasta))
                ->when($f["medico_id"] ?? null, fn ($c, $v) => $c->where("me.medico_id", $v)),
        };
    }

    // Horario, asignación, especialidad y médico de la ficha.
    private function conMedico(Builder $consulta, string $ficha): Builder
    {
        return $consulta->join("horarios_atencion as h", "h.id", "=", "{$ficha}.horario_atencion_id")
            ->join("medicos_especialidades as me", "me.id", "=", "h.medico_especialidad_id")
            ->join("especialidades as e", "e.id", "=", "me.especialidad_id")
            ->join("medicos as m", "m.id", "=", "me.medico_id")
            ->join("personas as pm", "pm.id", "=", "m.persona_id");
    }

    // CU40 agenda: fichas disponibles (habilitadas y libres), ocupadas, bloqueadas y % de ocupación (ocupadas / habilitadas).
    private function agregadosAgenda(): string
    {
        return "count(*) filter (where f.estado = 'HABILITADA' and ca.id is null) as disponibles,
            count(ca.id) as ocupadas,
            count(*) filter (where f.estado = 'BLOQUEADA') as bloqueadas,
            count(*) as total,
            round(100.0 * count(ca.id) / nullif(count(*) filter (where f.estado = 'HABILITADA' or ca.id is not null), 0), 1) as ocupacion";
    }

    // Edad en años cumplidos hoy (parámetro: la fecha de hoy en America/La_Paz).
    private function edadSql(): string
    {
        return "date_part('year', age(?::date, pp.fecha_nacimiento))::int";
    }

    // RN20: aseguradora del seguro ACTIVO, verificado, vigente hoy y con aseguradora ACTIVA (parámetros: hoy, hoy).
    private function seguroSql(): string
    {
        return "select a.nombre from seguros_paciente sp join aseguradoras a on a.id = sp.aseguradora_id
            where sp.paciente_id = p.id and sp.estado = 'ACTIVO' and sp.fecha_verificacion is not null
            and sp.vigente_desde <= ?::date and sp.vigente_hasta >= ?::date and a.estado = 'ACTIVO'
            order by sp.vigente_hasta desc limit 1";
    }

    private function hoy(): string
    {
        return today()->toDateString();
    }

    private function numero($valor): string
    {
        return rtrim(rtrim(number_format((float) $valor, 1, ",", "."), "0"), ",");
    }

    // Valores por defecto: todas las columnas, el mes actual (salvo pacientes) y el orden natural.
    private function porDefecto(string $tipo): array
    {
        $mes = $tipo === "pacientes" ? [] : ["desde" => today()->startOfMonth()->toDateString(), "hasta" => today()->endOfMonth()->toDateString()];

        return $mes + [
            "columnas" => array_keys($this->columnas($tipo)),
            "orden" => match ($tipo) { "pacientes" => "codigo", default => "fecha" },
            "direccion" => $tipo === "atenciones" ? "desc" : "asc",
        ];
    }

    // CU40 paso 2: reglas de los filtros de cada reporte.
    private function reglas(string $tipo, array $columnas): array
    {
        $fechas = [
            "desde" => ["nullable", "date_format:Y-m-d"],
            "hasta" => ["nullable", "date_format:Y-m-d", "after_or_equal:desde"],
        ];
        $comunes = [
            "columnas" => ["nullable", "array", "min:1"],
            "columnas.*" => ["string", "in:" . implode(",", $columnas)],
            "orden" => ["nullable", "in:" . implode(",", $columnas)],
            "direccion" => ["nullable", "in:asc,desc"],
        ];
        $medico = ["medico_id" => ["nullable", "integer", "exists:medicos,id"]];
        $especialidad = ["especialidad_id" => ["nullable", "integer", "exists:especialidades,id"]];

        return $comunes + $fechas + match ($tipo) {
            "citas" => $medico + $especialidad + [
                "estado" => ["nullable", "in:" . implode(",", Cita::ESTADOS)],
                "origen" => ["nullable", "in:" . CitaService::ORIGEN_RECEPCION . "," . CitaService::ORIGEN_AUTOSERVICIO],
                "canal" => ["nullable", "in:WEB,VOZ"],
            ],
            "pacientes" => [
                "estado" => ["nullable", "in:ACTIVO,INACTIVO"],
                "sexo" => ["nullable", "in:M,F"],
                "edad_min" => ["nullable", "integer", "min:0", "max:130"],
                "edad_max" => ["nullable", "integer", "min:0", "max:130", "gte:edad_min"],
                "seguro" => ["nullable", "in:CON,SIN"],
            ],
            "atenciones" => $medico + $especialidad + ["cie10" => ["nullable", "string", "max:10", "regex:/^[A-Za-z0-9.]+$/"]],
            "agenda" => $medico,
        };
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "desde.date_format" => "La fecha «desde» no es válida.",
            "hasta.date_format" => "La fecha «hasta» no es válida.",
            "hasta.after_or_equal" => "La fecha «hasta» no puede ser anterior a «desde».",
            "columnas.min" => "Elija al menos una columna.",
            "columnas.*.in" => "Una de las columnas elegidas no es válida.",
            "orden.in" => "El orden elegido no es válido.",
            "direccion.in" => "La dirección del orden no es válida.",
            "medico_id.integer" => "El médico elegido no es válido.",
            "medico_id.exists" => "El médico elegido no existe.",
            "especialidad_id.integer" => "La especialidad elegida no es válida.",
            "especialidad_id.exists" => "La especialidad elegida no existe.",
            "estado.in" => "El estado elegido no es válido.",
            "origen.in" => "El origen elegido no es válido.",
            "canal.in" => "El canal elegido no es válido.",
            "sexo.in" => "El sexo elegido no es válido.",
            "edad_min.integer" => "La edad mínima debe ser un número entero.",
            "edad_min.min" => "La edad mínima no puede ser negativa.",
            "edad_min.max" => "La edad mínima no es válida.",
            "edad_max.integer" => "La edad máxima debe ser un número entero.",
            "edad_max.min" => "La edad máxima no puede ser negativa.",
            "edad_max.max" => "La edad máxima no es válida.",
            "edad_max.gte" => "La edad máxima no puede ser menor que la mínima.",
            "seguro.in" => "La opción de seguro no es válida.",
            "cie10.max" => "El código CIE-10 no puede tener más de 10 caracteres.",
            "cie10.regex" => "El código CIE-10 solo admite letras, números y punto.",
        ];
    }
}
