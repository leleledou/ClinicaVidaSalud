<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\CarbonInterface;

class RangoReferencia extends Model
{
    protected $table = "rangos_referencia";
    public $timestamps = false;

    protected $fillable = [
        "parametro_laboratorio_id",
        "edad_min_meses",
        "edad_max_meses",
        "sexo",
        "valor_min",
        "valor_max",
        "critico_min",
        "critico_max",
        "vigente_desde",
        "vigente_hasta"
    ];

    // CU33 paso 3: sexo del rango (ck_rangos_sexo; null = ambos).
    public const SEXOS = ["" => "Ambos", "M" => "Masculino", "F" => "Femenino"];

    protected function casts(): array
    {
        return [
            "vigente_desde" => "date:Y-m-d",
            "vigente_hasta" => "date:Y-m-d"
        ];
    }

    // SE_EVALUA_CON: el parámetro del rango.
    public function parametro()
    {
        return $this->belongsTo(ParametroLaboratorio::class, "parametro_laboratorio_id");
    }

    // APLICADO_A: resultados interpretados con este rango (si hay alguno, el rango no se edita).
    public function resultados()
    {
        return $this->hasMany(ResultadoLaboratorio::class, "rango_referencia_id");
    }

    /**
     * CU33 paso 3 / ex_rangos_referencia_solape: otro rango del mismo parámetro cuyas edades y vigencias
     * se cruzan con las dadas (ambos extremos incluidos; vigencia sin fin = abierta), del mismo sexo o
     * de «Ambos» contra un sexo (así cada paciente tiene como máximo un rango aplicable, como en
     * LaboratorioCatalogoSeeder). Devuelve el primero que se cruza o null. $idIgnorar: el propio rango.
     */
    public static function solapado(int $parametroId, int $edadMin, int $edadMax, ?string $sexo, string $desde, ?string $hasta, ?int $idIgnorar = null): ?self
    {
        return self::where("parametro_laboratorio_id", $parametroId)
            ->when($sexo !== null, fn ($consulta) => $consulta->where(fn ($s) => $s->whereNull("sexo")->orWhere("sexo", $sexo)))
            ->whereRaw("int4range(edad_min_meses, edad_max_meses, '[]') && int4range(?, ?, '[]')", [$edadMin, $edadMax])
            ->whereRaw("daterange(vigente_desde, vigente_hasta, '[]') && daterange(?::date, ?::date, '[]')", [$desde, $hasta])
            ->when($idIgnorar, fn ($consulta) => $consulta->where("id", "<>", $idIgnorar))
            ->orderBy("edad_min_meses")
            ->first();
    }

    // CU35 paso 6: meses cumplidos entre el nacimiento y la fecha (como RegistroClinico::edadMeses).
    public static function edadMeses(CarbonInterface $nacimiento, CarbonInterface $fecha): int
    {
        $diferencia = $nacimiento->copy()->startOfDay()->diff($fecha->copy()->startOfDay());

        return $diferencia->y * 12 + $diferencia->m;
    }

    /**
     * CU35 paso 6 (RN23): rango aplicable, como RegistroClinico::rango: el del parámetro, vigente en la
     * fecha, cuya banda de edad contiene la edad en meses del paciente en esa fecha y cuyo sexo es el del
     * paciente o NULL (ambos). Los rangos no se cruzan (ex_rangos_referencia_solape): hay uno o ninguno.
     */
    public static function aplicable(int $parametroId, CarbonInterface $nacimiento, ?string $sexo, CarbonInterface $fecha): ?self
    {
        $meses = self::edadMeses($nacimiento, $fecha);
        $dia = $fecha->toDateString();

        return self::where("parametro_laboratorio_id", $parametroId)
            ->where("edad_min_meses", "<=", $meses)
            ->where("edad_max_meses", ">=", $meses)
            ->where(fn ($s) => $s->whereNull("sexo")->when($sexo !== null, fn ($o) => $o->orWhere("sexo", $sexo)))
            ->whereDate("vigente_desde", "<=", $dia)
            ->where(fn ($v) => $v->whereNull("vigente_hasta")->orWhereDate("vigente_hasta", ">=", $dia))
            ->orderBy("id")
            ->first();
    }

    // CU35 paso 6: interpretación de un valor con este rango; los límites críticos se evalúan primero
    // (como RegistroClinico::interpretar).
    public function interpretar(float $valor): string
    {
        return match (true) {
            $this->critico_min !== null && $valor < (float) $this->critico_min => "CRITICO_BAJO",
            $this->critico_max !== null && $valor > (float) $this->critico_max => "CRITICO_ALTO",
            $this->valor_min !== null && $valor < (float) $this->valor_min => "BAJO",
            $this->valor_max !== null && $valor > (float) $this->valor_max => "ALTO",
            default => "NORMAL",
        };
    }

    // CU33 paso 3: edad en meses con su equivalente («216 meses = 18 años», «18 meses = 1 año y 6 meses»).
    public static function textoMeses(int $meses): string
    {
        $anios = intdiv($meses, 12);
        $resto = $meses % 12;
        $texto = match (true) {
            $anios === 0 => "{$resto} " . ($resto === 1 ? "mes" : "meses"),
            $resto === 0 => "{$anios} " . ($anios === 1 ? "año" : "años"),
            default => "{$anios} " . ($anios === 1 ? "año" : "años") . " y {$resto} " . ($resto === 1 ? "mes" : "meses"),
        };

        return "{$meses} meses = {$texto}";
    }

    // CU33 paso 3: descripción breve del rango (para mensajes y listas).
    public function descripcion(): string
    {
        return "edad {$this->edad_min_meses}–{$this->edad_max_meses} meses, " . mb_strtolower(self::SEXOS[$this->sexo ?? ""])
            . ", vigente desde " . $this->vigente_desde->format("d/m/Y") . ($this->vigente_hasta ? " hasta " . $this->vigente_hasta->format("d/m/Y") : " sin fin");
    }

    // CU30 paso 3: rango normal en texto («11 – 15,5», «≥ 4», «≤ 200»).
    public function texto(): string
    {
        return match (true) {
            $this->valor_min !== null && $this->valor_max !== null => self::numero($this->valor_min) . " – " . self::numero($this->valor_max),
            $this->valor_min !== null => "≥ " . self::numero($this->valor_min),
            $this->valor_max !== null => "≤ " . self::numero($this->valor_max),
            default => "—",
        };
    }

    // CU33 paso 3: valores críticos en texto («< 7 o > 20»).
    public function textoCriticos(): string
    {
        $partes = array_filter([
            $this->critico_min !== null ? "< " . self::numero($this->critico_min) : null,
            $this->critico_max !== null ? "> " . self::numero($this->critico_max) : null,
        ]);

        return $partes ? implode(" o ", $partes) : "—";
    }

    // Número decimal sin ceros sobrantes y con coma («11,5»).
    public static function numero($valor): string
    {
        return str_replace(".", ",", rtrim(rtrim((string) $valor, "0"), "."));
    }
}
