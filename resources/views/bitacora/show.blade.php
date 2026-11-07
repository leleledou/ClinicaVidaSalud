{{-- CU21 paso 4 Consultar bitácora (RF-06): detalle de un registro. Los datos anteriores y nuevos
     se muestran uno al lado del otro y se resaltan las claves que cambiaron. --}}
@php
    // Valor de un campo para mostrar: nulo, booleano, lista/objeto (JSON) o texto.
    $mostrarValor = fn ($valor) => match (true) {
        $valor === null => '—',
        is_bool($valor) => $valor ? 'true' : 'false',
        is_array($valor) => json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        default => (string) $valor,
    };
    $sinDato = '<span class="text-slate-400">(sin dato)</span>';

    // «Volver a la lista» conserva los filtros y la página si se llegó desde la lista.
    $anterior = url()->previous();
    $volver = str_starts_with($anterior, route('bitacora.listar')) && $anterior !== url()->current() ? $anterior : route('bitacora.listar');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Seguridad / Bitácora" :titulo="'Registro #' . $registro->id">
            <a href="{{ $volver }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        {{-- Datos del registro --}}
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Datos del registro</h2>
            <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                <div class="dato">
                    <dt>Fecha y hora</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse($registro->fecha_hora)->format('d/m/Y H:i:s') }}</dd>
                </div>
                <div class="dato">
                    <dt>Usuario</dt>
                    <dd>
                        @if ($registro->usuario)
                            {{ $registro->usuario->persona?->nombre_completo ?? $registro->usuario->nombre_usuario }}
                            <span class="text-slate-500">({{ $registro->usuario->correo }})</span>
                        @else
                            Sistema
                        @endif
                    </dd>
                </div>
                <div class="dato">
                    <dt>Módulo</dt>
                    <dd>{{ $registro->modulo }}</dd>
                </div>
                <div class="dato">
                    <dt>Acción</dt>
                    <dd>{{ $registro->accion }}</dd>
                </div>
                <div class="dato">
                    <dt>Registro afectado</dt>
                    <dd>
                        @if ($registro->tabla_afectada)
                            {{ $registro->tabla_afectada }}{{ $registro->registro_id ? ' #' . $registro->registro_id : '' }}
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div class="dato">
                    <dt>Canal</dt>
                    <dd>{{ $registro->canal }}</dd>
                </div>
                <div class="dato">
                    <dt>Resultado</dt>
                    <dd>
                        <span @class(['insignia', 'insignia-activo' => $registro->exito, 'insignia-cancelada' => ! $registro->exito])>
                            {{ $registro->exito ? 'Éxito' : 'Fallido' }}
                        </span>
                    </dd>
                </div>
                <div class="dato">
                    <dt>IP</dt>
                    <dd>{{ $registro->ip ?? '—' }}</dd>
                </div>
                {{-- RF-06: las operaciones por voz guardan lo que dijo el usuario --}}
                @if ($registro->canal === 'VOZ')
                    <div class="dato xl:col-span-2">
                        <dt>Transcripción de voz</dt>
                        <dd class="whitespace-pre-line">{{ $registro->transcripcion_voz ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </div>

        {{-- Datos anteriores y nuevos, clave por clave --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Datos anteriores y nuevos</h2>

            @if (empty($claves))
                <p class="text-sm text-slate-500">Este registro no guarda datos anteriores ni nuevos.</p>
            @else
                @if (! empty($cambiadas))
                    <p class="alerta-info">Las claves resaltadas cambiaron ({{ count($cambiadas) }}).</p>
                @endif

                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Clave</th>
                                <th>Valor anterior</th>
                                <th>Valor nuevo</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($claves as $clave)
                                @php $cambio = in_array($clave, $cambiadas, true); @endphp
                                <tr @class(['align-top', 'bg-amber-50' => $cambio])>
                                    <td @class(['whitespace-nowrap font-mono text-xs', 'font-bold text-amber-800' => $cambio])>{{ $clave }}</td>
                                    <td class="break-all">
                                        @if (array_key_exists($clave, $anteriores))
                                            {{ $mostrarValor($anteriores[$clave]) }}
                                        @else
                                            {!! $sinDato !!}
                                        @endif
                                    </td>
                                    <td @class(['break-all', 'font-semibold' => $cambio])>
                                        @if (array_key_exists($clave, $nuevos))
                                            {{ $mostrarValor($nuevos[$clave]) }}
                                        @else
                                            {!! $sinDato !!}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
