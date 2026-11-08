{{-- CU40 Generar reportes (RF-41) paso 4: PDF (dompdf, A4 horizontal). Encabezado con la clínica, el reporte, los
     filtros aplicados, la fecha y el usuario; totales y la tabla con las columnas elegidas. A2: máximo 2000 filas. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de {{ mb_strtolower($titulo) }}</title>
    <style>
        @page { margin: 22px 26px 34px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #1e293b; }
        h1 { font-size: 15px; margin: 0; color: #1e3a5f; }
        .clinica { font-size: 11px; font-weight: bold; color: #0f766e; }
        .meta { margin: 6px 0 8px; color: #475569; }
        .meta span { margin-right: 14px; }
        .aviso { background: #fef3c7; border: 1px solid #f59e0b; padding: 5px; margin-bottom: 8px; }
        .totales { margin-bottom: 8px; }
        .totales td { vertical-align: top; padding-right: 18px; }
        .totales b { color: #1e3a5f; }
        table.datos { width: 100%; border-collapse: collapse; }
        table.datos th { background: #1e3a5f; color: #fff; text-align: left; padding: 4px; }
        table.datos td { border-bottom: 1px solid #e2e8f0; padding: 3px 4px; }
        table.datos tr:nth-child(even) td { background: #f8fafc; }
        .pie { position: fixed; bottom: -22px; left: 0; right: 0; text-align: right; color: #64748b; font-size: 8px; }
    </style>
</head>
<body>
    <div class="clinica">Clínica Vida Salud</div>
    <h1>Reporte de {{ mb_strtolower($titulo) }}</h1>
    <div class="meta">
        @foreach ($filtrosTexto as $etiqueta => $valor)
            <span><b>{{ $etiqueta }}:</b> {{ $valor }}</span>
        @endforeach
        <br>
        <span><b>Generado:</b> {{ now()->format('d/m/Y H:i') }}</span>
        <span><b>Usuario:</b> {{ $usuario }}</span>
    </div>

    @if ($recortado)
        <div class="aviso">El reporte tiene más de {{ number_format(\App\Services\ReporteService::MAX_PDF, 0, ',', '.') }} filas: el PDF muestra las primeras {{ number_format(\App\Services\ReporteService::MAX_PDF, 0, ',', '.') }}. Use Excel/CSV para el reporte completo o ajuste los filtros.</div>
    @endif

    <table class="totales">
        <tr>
            @foreach ($totales as $grupo => $valores)
                <td>
                    <b>{{ $grupo }}</b><br>
                    @forelse ($valores as $etiqueta => $valor)
                        {{ $etiqueta }}: {{ $valor }}<br>
                    @empty
                        Sin datos.
                    @endforelse
                </td>
            @endforeach
        </tr>
    </table>

    <table class="datos">
        <thead>
            <tr>
                @foreach ($titulos as $columna)
                    <th>{{ $columna }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($filas as $fila)
                <tr>
                    @foreach ($fila as $valor)
                        <td>{{ $valor }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($titulos) }}">No hay datos con esos filtros.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="pie">Clínica Vida Salud · Reporte de {{ mb_strtolower($titulo) }}</div>
</body>
</html>
