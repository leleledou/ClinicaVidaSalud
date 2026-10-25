{{-- CU08: ficha de un consultorio con sus horarios de atención. --}}
@php
    $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    $fecha = fn ($valor) => \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Consultorio: {{ $consultorio->nombre }}
            </h2>
            <a href="{{ route('consultorios.listar') }}" class="text-sm text-indigo-600 hover:underline">Volver a la lista</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Datos del consultorio --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="font-medium text-gray-500">Nombre</dt>
                        <dd class="mt-1 text-gray-900">{{ $consultorio->nombre }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Ubicación</dt>
                        <dd class="mt-1 text-gray-900">{{ $consultorio->ubicacion }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Estado</dt>
                        <dd class="mt-1"><x-estado :estado="$consultorio->estado" /></dd>
                    </div>
                </dl>
            </div>

            {{-- Horarios del consultorio --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Horarios de atención</h3>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-2">Médico</th>
                                <th class="px-3 py-2">Especialidad</th>
                                <th class="px-3 py-2">Día</th>
                                <th class="px-3 py-2">Horario</th>
                                <th class="px-3 py-2">Vigencia</th>
                                <th class="px-3 py-2">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($horarios as $horario)
                                <tr>
                                    <td class="px-3 py-3">
                                        <span class="text-gray-500">{{ $horario->medico_codigo }}</span>
                                        {{ $horario->nombres }} {{ $horario->apellido_paterno }} {{ $horario->apellido_materno }}
                                    </td>
                                    <td class="px-3 py-3">{{ $horario->especialidad }}</td>
                                    <td class="px-3 py-3">{{ $dias[$horario->dia_semana] ?? $horario->dia_semana }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ substr($horario->hora_inicio, 0, 5) }} – {{ substr($horario->hora_fin, 0, 5) }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">
                                        Desde {{ $fecha($horario->vigente_desde) }}
                                        {{ $horario->vigente_hasta ? 'hasta ' . $fecha($horario->vigente_hasta) : '(sin fecha de fin)' }}
                                    </td>
                                    <td class="px-3 py-3"><x-estado :estado="$horario->estado" /></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-6 text-center text-gray-500">El consultorio no tiene horarios asignados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
