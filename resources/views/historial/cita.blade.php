{{-- CU20 pasos 4 y 5: trazabilidad de una cita (RF09, RF12): sus datos y la línea de tiempo de
     historiales_cita en orden cronológico (solo inserción: nada se borra ni se modifica, RN10). --}}
@php
    $ficha = $cita->ficha;
    $fecha = \Illuminate\Support\Carbon::parse($ficha->fecha);
    $horas = fn ($f) => $f ? \Illuminate\Support\Carbon::parse($f->fecha)->format('d/m/Y') . ' ' . substr($f->hora_inicio, 0, 5) . '–' . substr($f->hora_fin, 0, 5) : null;
    $origenes = ['AUTOSERVICIO' => 'Reserva en línea', 'RECEPCION' => 'Recepción'];
    $punto = fn ($estado) => match ($estado) {
        'PROGRAMADA', 'CONFIRMADA' => 'bg-blue-500',
        'ATENDIDA' => 'bg-green-500',
        'CANCELADA' => 'bg-gray-400',
        default => 'bg-red-500',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Trazabilidad de la cita {{ $cita->codigo }}</h2>
            <div class="flex items-center gap-4">
                <a href="{{ route('historiales-cita.listar', ['paciente_id' => $cita->paciente_id]) }}" class="text-sm text-indigo-600 hover:underline">Historial del paciente</a>
                {{-- CU20 paso 5: el detalle con las acciones (CU16–CU18) --}}
                @if ($verDetalle)
                    <a href="{{ route('citas.mostrar', $cita->id) }}" class="inline-flex items-center px-4 py-2 bg-gray-800 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700">Ver la cita</a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU20 paso 4: datos de la cita --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-lg font-medium text-gray-900">Cita {{ $cita->codigo }}</h3>
                    <x-estado-cita :estado="$cita->estado" />
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="font-medium text-gray-500">Paciente</dt><dd>{{ $cita->paciente->persona->nombre_completo }} ({{ $cita->paciente->codigo }})</dd></div>
                    <div><dt class="font-medium text-gray-500">Médico</dt><dd>{{ \App\Services\CitaService::tituloMedico($cita->medico()->persona) }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Especialidad</dt><dd>{{ $cita->especialidad()->nombre }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Fecha y hora</dt><dd>{{ \App\Services\AgendaService::DIAS[$fecha->dayOfWeekIso] }} {{ $horas($ficha) }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Origen</dt><dd>{{ $origenes[$cita->origen] ?? ($cita->origen ?? '—') }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Fecha de registro</dt><dd>{{ $cita->fecha_registro?->format('d/m/Y H:i') }} ({{ $cita->usuarioRegistra->nombre_usuario }})</dd></div>
                    @if ($cita->estado === 'CANCELADA')
                        <div class="sm:col-span-2"><dt class="font-medium text-gray-500">Motivo de cancelación</dt><dd>{{ $cita->motivo_cancelacion }}</dd></div>
                    @endif
                    {{-- CU16 A1: enlace entre la original y la que la reemplaza --}}
                    @if ($cita->citaReemplazada)
                        <div><dt class="font-medium text-gray-500">Reemplaza a</dt><dd><a href="{{ route('historiales-cita.mostrar', $cita->citaReemplazada->id) }}" class="text-indigo-600 hover:underline">{{ $cita->citaReemplazada->codigo }}</a></dd></div>
                    @endif
                    @if ($cita->reemplazadaPor)
                        <div><dt class="font-medium text-gray-500">Reemplazada por</dt><dd><a href="{{ route('historiales-cita.mostrar', $cita->reemplazadaPor->id) }}" class="text-indigo-600 hover:underline">{{ $cita->reemplazadaPor->codigo }}</a></dd></div>
                    @endif
                </dl>
            </div>

            {{-- CU20 paso 5: línea de tiempo en orden cronológico --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Línea de tiempo</h3>
                <ol class="relative border-s border-gray-200 ms-2">
                    @forelse ($cita->historiales as $historial)
                        <li class="mb-6 ms-6">
                            <span class="absolute -start-1.5 mt-1.5 h-3 w-3 rounded-full {{ $punto($historial->estado_nuevo) }}"></span>
                            <p class="text-xs text-gray-500">{{ $historial->fecha_hora?->format('d/m/Y H:i') }} · {{ $historial->usuario?->nombre_usuario ?? 'SISTEMA' }} · {{ $historial->canal }}</p>
                            <p class="mt-1 text-sm font-medium text-gray-900">
                                {{ $historial->estado_anterior ?? 'Registro' }} → {{ $historial->estado_nuevo }}
                            </p>
                            {{-- Ficha anterior → nueva (RN18: un cambio de ficha es una reprogramación) --}}
                            @if ($historial->fichaAnterior || $historial->fichaNueva)
                                <p class="text-sm text-gray-700">
                                    Ficha: @if ($historial->fichaAnterior) {{ $horas($historial->fichaAnterior) }} → @endif {{ $horas($historial->fichaNueva) ?? '—' }}
                                </p>
                            @endif
                            <p class="text-sm text-gray-600">{{ $historial->motivo ?? 'Sin motivo' }}</p>
                        </li>
                    @empty
                        <li class="ms-6 text-sm text-gray-500">Sin registros en el historial.</li>
                    @endforelse
                </ol>
            </div>
        </div>
    </div>
</x-app-layout>
