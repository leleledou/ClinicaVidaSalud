<?php

namespace App\Http\Controllers;

use App\Services\ReporteService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * CU40 Generar reportes (RF-41). Actores: Administrador y Recepcionista (permiso reportes.consultar, en las
 * rutas). Controlador de interfaz: valida, pide la consulta a ReporteService y responde en pantalla, PDF
 * (dompdf) o CSV. Pantalla, PDF y CSV usan la MISMA consulta con los mismos filtros, columnas y orden.
 */
class ReporteController extends Controller
{
    // Campos de la petición además de los filtros de cada reporte.
    private const CAMPOS = ["formato"];

    public function __construct(private ReporteService $reportes)
    {
    }

    // CU40 paso 1: elegir el reporte.
    public function index()
    {
        return view("reportes.index", ["tipos" => ReporteService::TIPOS, "descripciones" => ReporteService::DESCRIPCIONES]);
    }

    // CU40 pasos 2 y 3: filtros, columnas y orden; tabla paginada (25) con totales. A1: filtros no válidos.
    public function show(Request $request, string $tipo)
    {
        [$filtros, $errores] = $this->reportes->filtros($tipo, $request->query());
        $filas = $this->reportes->consulta($tipo, $filtros)->paginate(ReporteService::POR_PAGINA)->withQueryString();

        // CU40 paso 5: GENERAR_REPORTE al generarlo (no en cada página siguiente).
        if ($filas->currentPage() === 1) {
            $this->reportes->registrar("GENERAR_REPORTE", $tipo, $filtros, $request->user(), ["filas" => $filas->total()]);
        }

        return view("reportes.show", [
            "tipo" => $tipo,
            "titulo" => ReporteService::TIPOS[$tipo],
            "filtros" => $filtros,
            "errores" => $errores,
            "columnas" => $this->reportes->columnas($tipo),
            "filas" => $filas,
            // Las filas de la página con las columnas elegidas, ya formateadas (como en el PDF y el CSV).
            "filasTexto" => $filas->getCollection()->map(fn ($registro) => $this->reportes->fila($tipo, $registro, $filtros["columnas"])),
            "totales" => $this->reportes->totales($tipo, $filtros),
            "opciones" => $this->reportes->opciones(),
            "formatos" => ReporteService::FORMATOS,
        ]);
    }

    // CU40 paso 4: exportar con los mismos filtros (formato=pdf|csv). A1: filtros no válidos → vuelve a la pantalla.
    public function exportar(Request $request, string $tipo)
    {
        $request->validate([self::CAMPOS[0] => ["required", "in:" . implode(",", array_keys(ReporteService::FORMATOS))]], [
            "formato.required" => "Elija el formato de exportación.",
            "formato.in" => "El formato de exportación no es válido.",
        ]);
        [$filtros, $errores] = $this->reportes->filtros($tipo, $request->except(self::CAMPOS));
        if ($errores->isNotEmpty()) {
            return redirect()->route("reportes.mostrar", ["tipo" => $tipo] + $request->except(self::CAMPOS))->withErrors($errores);
        }

        $formato = $request->input(self::CAMPOS[0]);
        $columnas = $this->reportes->columnas($tipo);
        $titulos = array_map(fn ($clave) => $columnas[$clave][0], $filtros["columnas"]);
        $archivo = "reporte-{$tipo}-" . now()->format("Ymd-His");
        $consulta = $this->reportes->consulta($tipo, $filtros);

        // CU40 paso 5: EXPORTAR_REPORTE con el formato y los filtros.
        $this->reportes->registrar("EXPORTAR_REPORTE", $tipo, $filtros, $request->user(), ["formato" => $formato]);

        if ($formato === "pdf") {
            // CU40 paso 4 / A2: hasta MAX_PDF filas; si hay más, el PDF lo avisa.
            $registros = $consulta->limit(ReporteService::MAX_PDF + 1)->get();

            return Pdf::loadView("reportes.pdf", [
                "titulo" => ReporteService::TIPOS[$tipo],
                "filtrosTexto" => $this->reportes->textoFiltros($tipo, $filtros),
                "titulos" => $titulos,
                "filas" => $registros->take(ReporteService::MAX_PDF)->map(fn ($r) => $this->reportes->fila($tipo, $r, $filtros["columnas"])),
                "recortado" => $registros->count() > ReporteService::MAX_PDF,
                "totales" => $this->reportes->totales($tipo, $filtros),
                "usuario" => $request->user()->persona->nombre_completo . " ({$request->user()->nombre_usuario})",
            // Solo los caracteres usados de la fuente: el PDF pesa decenas de KB, no casi 1 MB.
            ])->setPaper("a4", "landscape")->setOption("isFontSubsettingEnabled", true)->download("{$archivo}.pdf");
        }

        // CU40 paso 4: CSV en UTF-8 con BOM y separador «;» (Excel en español lo abre directo), en streaming.
        return response()->streamDownload(function () use ($consulta, $tipo, $filtros, $titulos) {
            $salida = fopen("php://output", "w");
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, $titulos, ";");
            foreach ($consulta->cursor() as $registro) {
                fputcsv($salida, array_map(fn ($valor) => $this->celdaSegura($valor), $this->reportes->fila($tipo, $registro, $filtros["columnas"])), ";");
            }
            fclose($salida);
        }, "{$archivo}.csv", ["Content-Type" => "text/csv; charset=UTF-8"]);
    }

    // CSV: una celda que empieza con = + - @ no se interpreta como fórmula en Excel.
    private function celdaSegura(string $valor): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valor) ? "'" . $valor : $valor;
    }
}
