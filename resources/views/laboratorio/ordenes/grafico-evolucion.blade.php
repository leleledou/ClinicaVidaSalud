{{-- CU36 Revisar resultados y evolución (RF-37) paso 4: gráfico SVG (sin librerías) de la evolución de un parámetro
     NUMERICO con todos los resultados del paciente, en orden de fecha. Franja sombreada: rango normal del resultado más
     reciente (si solo hay mínimo o máximo, va hasta el borde). Puntos coloreados por interpretación con tooltip nativo.
     Debajo, la tabla fecha / valor / interpretación / orden. A5: con un solo resultado, el punto y un aviso.
     Recibe $parametro, $resultados (ResultadoLaboratorio::evolucion) y $ordenActual (id; sus puntos van resaltados). --}}
@php
    // Medidas del lienzo (viewBox): el SVG ocupa el 100 % del ancho y escala en el celular.
    [$ancho, $alto, $izq, $der, $arriba, $abajo] = [640, 260, 60, 20, 16, 44];
    $anchoUtil = $ancho - $izq - $der;
    $altoUtil = $alto - $arriba - $abajo;

    $unidad = $parametro->unidad;
    $valores = $resultados->map(fn ($r) => (float) $r->valor_numerico);
    $rango = $resultados->last()->rango;
    $min = $rango?->valor_min !== null ? (float) $rango->valor_min : null;
    $max = $rango?->valor_max !== null ? (float) $rango->valor_max : null;
    $hayFranja = $min !== null || $max !== null;

    // Eje Y: los valores y los límites del rango, con un margen y marcas «redondas» (1, 2 o 5 × 10ⁿ).
    $extremos = $valores->concat(array_filter([$min, $max], fn ($v) => $v !== null));
    [$pisoDatos, $techoDatos] = [$extremos->min(), $extremos->max()];
    if ($techoDatos - $pisoDatos < 1e-9) {
        [$pisoDatos, $techoDatos] = [$pisoDatos - max(1, abs($pisoDatos) * 0.1), $techoDatos + max(1, abs($techoDatos) * 0.1)];
    }
    $margen = ($techoDatos - $pisoDatos) * 0.1;
    $bruto = ($techoDatos - $pisoDatos + 2 * $margen) / 4;
    $potencia = 10 ** floor(log10($bruto));
    $paso = collect([1, 2, 5, 10])->first(fn ($m) => $m * $potencia >= $bruto) * $potencia;
    $piso = floor(($pisoDatos - $margen) / $paso) * $paso;
    if ($piso < 0 && $pisoDatos >= 0) {
        $piso = 0;
    }
    $techo = ceil(($techoDatos + $margen) / $paso) * $paso;
    $decimales = max(0, (int) -floor(log10($paso)));
    $marcas = [];
    for ($v = $piso; $v <= $techo + $paso / 1000; $v += $paso) {
        $marcas[] = round($v, $decimales);
    }

    $y = fn (float $v) => round($arriba + $altoUtil * (1 - ($v - $piso) / ($techo - $piso)), 1);

    // Eje X: proporcional al tiempo; si todos tienen la misma fecha (o hay uno), repartidos de forma pareja.
    $tiempos = $resultados->map(fn ($r) => $r->fecha_resultado->getTimestamp());
    [$t0, $t1] = [$tiempos->min(), $tiempos->max()];
    $n = $resultados->count();
    $x = fn (int $i) => round($n === 1 ? $izq + $anchoUtil / 2
        : ($t1 > $t0 ? $izq + $anchoUtil * ($tiempos[$i] - $t0) / ($t1 - $t0) : $izq + $anchoUtil * $i / ($n - 1)), 1);

    $puntos = $resultados->values()->map(fn ($r, $i) => [
        'x' => $x($i), 'y' => $y((float) $r->valor_numerico), 'resultado' => $r,
    ]);

    // Fechas del eje X sin encimarse (al menos 56 unidades entre etiquetas; la última siempre).
    $etiquetas = [];
    $ultimaX = -INF;
    foreach ($puntos as $i => $p) {
        $esUltimo = $i === $n - 1;
        if ($p['x'] - $ultimaX >= 56 || $esUltimo) {
            if ($esUltimo && $p['x'] - $ultimaX < 56) {
                array_pop($etiquetas);
            }
            $etiquetas[] = $p;
            $ultimaX = $p['x'];
        }
    }

    // Franja del rango normal: hasta el borde si falta un límite.
    $franjaArriba = $max !== null ? max($arriba, $y($max)) : $arriba;
    $franjaAbajo = $min !== null ? min($arriba + $altoUtil, $y($min)) : $arriba + $altoUtil;
    $textoUnidad = $unidad ? " {$unidad}" : '';
@endphp

<section class="space-y-3" aria-label="Evolución de {{ $parametro->nombre }}">
    <h3 class="font-semibold text-marino-900">{{ $parametro->nombre }}{{ $unidad ? ' (' . $unidad . ')' : '' }}</h3>

    <svg viewBox="0 0 {{ $ancho }} {{ $alto }}" style="width: 100%; height: auto;" role="img"
         aria-label="Evolución de {{ $parametro->nombre }}: {{ $resultados->map(fn ($r) => $r->fecha_resultado->format('d/m/Y') . ' ' . $r->textoValor())->implode('; ') }}">
        {{-- Franja sombreada del rango normal --}}
        @if ($hayFranja)
            <rect x="{{ $izq }}" y="{{ $franjaArriba }}" width="{{ $anchoUtil }}" height="{{ max(0, $franjaAbajo - $franjaArriba) }}" fill="#99f6e4" fill-opacity="0.35" />
        @endif

        {{-- Eje Y: líneas guía y valores --}}
        @foreach ($marcas as $marca)
            <line x1="{{ $izq }}" x2="{{ $ancho - $der }}" y1="{{ $y($marca) }}" y2="{{ $y($marca) }}" stroke="#e2e8f0" stroke-width="1" />
            <text x="{{ $izq - 8 }}" y="{{ $y($marca) + 4 }}" text-anchor="end" font-size="11" fill="#475569">{{ \App\Models\RangoReferencia::numero($marca) }}</text>
        @endforeach
        @if ($unidad)
            <text x="12" y="{{ $arriba + $altoUtil / 2 }}" transform="rotate(-90 12 {{ $arriba + $altoUtil / 2 }})" text-anchor="middle" font-size="11" fill="#475569">{{ $unidad }}</text>
        @endif

        {{-- Eje X: fechas (dd/mm/aa) --}}
        <line x1="{{ $izq }}" x2="{{ $ancho - $der }}" y1="{{ $arriba + $altoUtil }}" y2="{{ $arriba + $altoUtil }}" stroke="#94a3b8" stroke-width="1" />
        @foreach ($etiquetas as $p)
            <text x="{{ $p['x'] }}" y="{{ $arriba + $altoUtil + 18 }}" text-anchor="middle" font-size="11" fill="#475569">{{ $p['resultado']->fecha_resultado->format('d/m/y') }}</text>
        @endforeach

        {{-- Línea que une los puntos --}}
        @if ($n > 1)
            <polyline points="{{ $puntos->map(fn ($p) => $p['x'] . ',' . $p['y'])->implode(' ') }}" fill="none" stroke="#1e3a5f" stroke-width="2" stroke-linejoin="round" />
        @endif

        {{-- Puntos coloreados por interpretación, con tooltip nativo --}}
        @foreach ($puntos as $p)
            @php $r = $p['resultado']; @endphp
            <circle cx="{{ $p['x'] }}" cy="{{ $p['y'] }}" r="{{ (int) $r->orden_laboratorio_id === (int) $ordenActual ? 7 : 5 }}" fill="{{ $r->color() }}"
                    stroke="{{ (int) $r->orden_laboratorio_id === (int) $ordenActual ? '#1e3a5f' : '#ffffff' }}" stroke-width="2">
                <title>{{ $r->fecha_resultado->format('d/m/Y H:i') }} · {{ $r->textoValor() }} · {{ $r->textoInterpretacion() }} ({{ $r->orden->codigo }})</title>
            </circle>
        @endforeach
    </svg>

    {{-- Leyenda --}}
    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-slate-600">
        @if ($hayFranja)
            <span class="inline-flex items-center gap-1.5">
                <span class="inline-block h-3 w-5 rounded-sm" style="background: rgba(153, 246, 228, 0.6);"></span>
                Rango normal {{ $rango->texto() }}{{ $textoUnidad }} (el aplicado al resultado más reciente)
            </span>
        @else
            {{-- CU36 A6: el resultado más reciente no tiene rango aplicado --}}
            <span>Sin rango normal en el resultado más reciente: no se sombrea ninguna franja.</span>
        @endif
        <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-full" style="background: #0f766e;"></span>Normal</span>
        <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-full" style="background: #d97706;"></span>Bajo o alto</span>
        <span class="inline-flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-full" style="background: #dc2626;"></span>Crítico</span>
        <span>El punto más grande es el de esta orden.</span>
    </div>

    {{-- CU36 A5: un solo resultado --}}
    @if ($n === 1)
        <p class="alerta-info">Se necesita más de un resultado para ver la evolución.</p>
    @endif

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead><tr><th>Fecha</th><th>Valor</th><th>Interpretación</th><th>Orden</th></tr></thead>
            <tbody>
                @foreach ($resultados as $r)
                    <tr>
                        <td class="whitespace-nowrap">{{ $r->fecha_resultado->format('d/m/Y') }}</td>
                        <td class="whitespace-nowrap font-semibold">{{ $r->textoValor() }}</td>
                        <td><span class="insignia {{ $r->claseInterpretacion() }}">{{ $r->textoInterpretacion() }}</span></td>
                        <td class="whitespace-nowrap">
                            @if ((int) $r->orden_laboratorio_id === (int) $ordenActual)
                                {{ $r->orden->codigo }} <span class="text-slate-500">(esta)</span>
                            @else
                                <a href="{{ route('ordenes-laboratorio.mostrar', $r->orden_laboratorio_id) }}" class="text-teal-700 hover:underline">{{ $r->orden->codigo }}</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
