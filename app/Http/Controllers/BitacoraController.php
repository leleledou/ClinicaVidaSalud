<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;

/**
 * CU21 Consultar bitácora (RF-06). Requiere el permiso bitacora.consultar.
 * Solo lectura: bitacoras es de solo inserción (trigger tg_bitacoras_no_update), no hay
 * crear, modificar ni eliminar.
 */
class BitacoraController extends Controller
{
    private const CANALES = ["WEB", "VOZ", "SISTEMA"];

    private const RESULTADOS = ["exito", "fallido"];

    // CU21 pasos 1 a 3: lista de la más reciente a la más antigua, con filtros opcionales combinables.
    public function index(Request $request)
    {
        // CU21 paso 2: módulos que aparecen en la bitácora (para el select).
        $modulos = Bitacora::query()->distinct()->orderBy("modulo")->pluck("modulo")->all();

        $filtros = [
            "desde" => $request->query("desde") ?: null,
            "hasta" => $request->query("hasta") ?: null,
            "usuario" => trim((string) $request->query("usuario", "")) ?: null,
            "modulo" => $request->query("modulo") ?: null,
            "accion" => trim((string) $request->query("accion", "")) ?: null,
            "canal" => $request->query("canal") ?: null,
            "resultado" => $request->query("resultado") ?: null,
        ];

        // Un filtro con error se informa y no se aplica; los demás sí.
        $validador = Validator::make($filtros, $this->reglas($modulos), $this->mensajes());
        $errores = $validador->fails() ? $validador->errors() : new MessageBag();
        foreach (array_keys($filtros) as $campo) {
            if ($errores->has($campo)) {
                $filtros[$campo] = null;
            }
        }

        // CU21 A1: «desde» no puede ser mayor que «hasta» (se ignoran las dos fechas).
        if ($filtros["desde"] && $filtros["hasta"] && $filtros["desde"] > $filtros["hasta"]) {
            $errores->add("desde", "La fecha desde no puede ser mayor que la fecha hasta.");
            $filtros["desde"] = $filtros["hasta"] = null;
        }

        // Escapa los comodines de LIKE que escriba el usuario.
        $escapar = fn (string $valor) => "%" . str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $valor) . "%";

        // CU21 paso 3: carga ansiosa del usuario y su persona (sin N+1).
        $registros = Bitacora::with("usuario.persona")
            // Por rango de fechas (sin whereDate, para usar el índice de fecha_hora).
            ->when($filtros["desde"], fn ($consulta, $desde) => $consulta->where("fecha_hora", ">=", Carbon::parse($desde)->startOfDay()))
            ->when($filtros["hasta"], fn ($consulta, $hasta) => $consulta->where("fecha_hora", "<", Carbon::parse($hasta)->addDay()->startOfDay()))
            // Usuario: correo, nombre de usuario o nombre de la persona.
            ->when($filtros["usuario"], fn ($consulta, $texto) => $consulta->whereHas("usuario", function ($usuario) use ($texto, $escapar) {
                $usuario->where(function ($condicion) use ($texto, $escapar) {
                    $condicion->whereRaw("upper(correo) LIKE upper(?)", [$escapar($texto)])
                        ->orWhereRaw("upper(nombre_usuario) LIKE upper(?)", [$escapar($texto)])
                        ->orWhereHas("persona", fn ($persona) => $persona->whereRaw(
                            "fn_normalizar_texto(nombres || ' ' || apellido_paterno || ' ' || coalesce(apellido_materno, '')) LIKE fn_normalizar_texto(?)", [$escapar($texto)]));
                });
            }))
            ->when($filtros["modulo"], fn ($consulta, $modulo) => $consulta->where("modulo", $modulo))
            ->when($filtros["accion"], fn ($consulta, $accion) => $consulta->whereRaw("upper(accion) LIKE upper(?)", [$escapar($accion)]))
            ->when($filtros["canal"], fn ($consulta, $canal) => $consulta->where("canal", $canal))
            ->when($filtros["resultado"], fn ($consulta, $resultado) => $consulta->where("exito", $resultado === "exito"))
            ->orderByDesc("fecha_hora")
            ->orderByDesc("id")
            ->paginate(20)
            ->withQueryString();

        return view("bitacora.index", [
            "registros" => $registros,
            "filtros" => $filtros,
            "errores" => $errores,
            "modulos" => $modulos,
            "canales" => self::CANALES,
        ]);
    }

    // CU21 paso 4: detalle de un registro con los datos anteriores y nuevos.
    public function show($id)
    {
        $registro = Bitacora::with("usuario.persona")->findOrFail($id);

        $anteriores = $registro->datos_anteriores ?? [];
        $nuevos = $registro->datos_nuevos ?? [];

        // Todas las claves, en el orden en que aparecen (primero las anteriores).
        $claves = array_values(array_unique(array_merge(array_keys($anteriores), array_keys($nuevos))));

        // Solo se comparan si hay datos de los dos lados (al crear no hay anteriores).
        $cambiadas = [];
        if ($registro->datos_anteriores !== null && $registro->datos_nuevos !== null) {
            $cambiadas = array_values(array_filter($claves, fn ($clave) => ! array_key_exists($clave, $anteriores)
                || ! array_key_exists($clave, $nuevos)
                || $anteriores[$clave] != $nuevos[$clave]));
        }

        return view("bitacora.show", compact("registro", "anteriores", "nuevos", "claves", "cambiadas"));
    }

    // Reglas de los filtros.
    private function reglas(array $modulos): array
    {
        return [
            "desde" => ["nullable", "date_format:Y-m-d"],
            "hasta" => ["nullable", "date_format:Y-m-d"],
            "usuario" => ["nullable", "string", "max:255"],
            "modulo" => ["nullable", "in:" . implode(",", $modulos)],
            "accion" => ["nullable", "string", "max:255"],
            "canal" => ["nullable", "in:" . implode(",", self::CANALES)],
            "resultado" => ["nullable", "in:" . implode(",", self::RESULTADOS)],
        ];
    }

    // Mensajes en español.
    private function mensajes(): array
    {
        return [
            "desde.date_format" => "La fecha desde no es válida.",
            "hasta.date_format" => "La fecha hasta no es válida.",
            "usuario.string" => "El usuario debe ser un texto.",
            "usuario.max" => "El usuario no puede superar los 255 caracteres.",
            "modulo.in" => "El módulo elegido no es válido.",
            "accion.string" => "La acción debe ser un texto.",
            "accion.max" => "La acción no puede superar los 255 caracteres.",
            "canal.in" => "El canal debe ser WEB, VOZ o SISTEMA.",
            "resultado.in" => "El resultado debe ser Éxito o Fallido.",
        ];
    }
}
