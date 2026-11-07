<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ConfiguracionSistema;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CU22 Gestionar configuración del sistema (RF-07). Requiere el permiso configuracion.gestionar.
 * No hay alta ni baja de parámetros (las claves las usa el código): solo se modifica el valor.
 */
class ConfiguracionSistemaController extends Controller
{
    // CU22 paso 5: mínimo de los parámetros enteros. Los que no figuran aquí tienen mínimo 1
    // (una edad de mayoría, una ventana de meses o un número de semanas igual a 0 rompería las reglas).
    private const MINIMOS = [
        "max_reprogramaciones" => 0,
        "tolerancia_inasistencia_min" => 0,
        "horas_minimas_cambio_web" => 0,
    ];

    private const MINIMO_POR_DEFECTO = 1;

    // CU22 pasos 1-2: lista de parámetros con su valor actual.
    public function index()
    {
        $configuraciones = ConfiguracionSistema::orderBy("clave")->get();

        return view("configuraciones.index", compact("configuraciones"));
    }

    // CU22 paso 3: formulario de modificación de un parámetro.
    public function show($clave)
    {
        $configuracion = ConfiguracionSistema::findOrFail($clave);

        return view("configuraciones.show", [
            "configuracion" => $configuracion,
            "minimo" => self::minimo($configuracion->clave),
        ]);
    }

    // CU22 pasos 4 a 6: modificar el valor. La clave viaja oculta en el formulario.
    public function update(Request $request)
    {
        $request->validate(
            ["clave" => ["required", "string", "exists:configuraciones_sistema,clave"]],
            ["clave.required" => "No se indicó el parámetro.", "clave.string" => "El parámetro indicado no es válido.", "clave.exists" => "El parámetro indicado no existe."]
        );
        $configuracion = ConfiguracionSistema::findOrFail($request->input("clave"));

        // CU22 paso 5: validar según el tipo de dato.
        if (is_string($request->input("valor"))) {
            $request->merge(["valor" => trim($request->input("valor"))]);
        }
        $request->validate($this->reglas($configuracion), $this->mensajes($configuracion));

        // Los enteros se guardan sin ceros a la izquierda ni signo «+».
        $nuevo = $configuracion->tipo_dato === "int" ? (string) (int) $request->input("valor") : $request->input("valor");
        $anterior = $configuracion->valor;

        // CU22 A1: si el valor no cambió, no se guarda ni se registra nada.
        if ($nuevo === $anterior) {
            return redirect()->route("configuraciones.listar")->with("aviso", "No hay cambios");
        }

        // CU22 paso 6: guardar y registrar en la bitácora.
        DB::transaction(function () use ($configuracion, $nuevo, $anterior, $request) {
            $configuracion->update(["valor" => $nuevo]);

            Bitacora::registrar("MODIFICAR_CONFIGURACION", "seguridad", $request->user()->id, true, "configuraciones_sistema", null, "WEB",
                ["clave" => $configuracion->clave, "valor" => $anterior], ["clave" => $configuracion->clave, "valor" => $nuevo]);
        });

        return redirect()->route("configuraciones.listar")->with("exito", "Parámetro {$configuracion->clave} actualizado");
    }

    // Mínimo de un parámetro entero (también lo usa el formulario).
    private static function minimo(string $clave): int
    {
        return self::MINIMOS[$clave] ?? self::MINIMO_POR_DEFECTO;
    }

    // CU22 paso 5: int = entero (con mínimo); bool = true/false; decimal = numérico; string = obligatorio, máx. 255.
    private function reglas(ConfiguracionSistema $configuracion): array
    {
        $entero = ["required", "integer", "min:" . self::minimo($configuracion->clave)];

        // La sesión de Laravel caduca a los SESSION_LIFETIME minutos: la inactividad no puede superarlos.
        if ($configuracion->clave === "minutos_inactividad_sesion") {
            $entero[] = "max:" . self::maximoInactividad();
        }

        return [
            "valor" => match ($configuracion->tipo_dato) {
                "int" => $entero,
                "bool" => ["required", "in:true,false"],
                "decimal" => ["required", "numeric"],
                default => ["required", "string", "max:255"],
            },
        ];
    }

    // Mensajes en español.
    private function mensajes(ConfiguracionSistema $configuracion): array
    {
        return [
            "valor.required" => "El campo Valor es obligatorio.",
            "valor.integer" => "El valor debe ser un número entero.",
            "valor.min" => "El valor debe ser como mínimo " . self::minimo($configuracion->clave) . ".",
            "valor.in" => "El valor debe ser true o false.",
            "valor.numeric" => "El valor debe ser un número.",
            "valor.string" => "El valor debe ser un texto.",
            "valor.max" => $configuracion->tipo_dato === "int"
                ? "El valor no puede ser mayor que " . self::maximoInactividad() . " minutos: es la duración máxima de la sesión (SESSION_LIFETIME)."
                : "El valor no puede superar los 255 caracteres.",
        ];
    }

    // Máximo de minutos_inactividad_sesion: la duración de la sesión (config/session.php, SESSION_LIFETIME).
    private static function maximoInactividad(): int
    {
        return (int) config("session.lifetime");
    }
}
