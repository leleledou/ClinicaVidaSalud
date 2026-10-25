{{-- CU06: ficha de una especialidad con los médicos que la ejercen. --}}
@php
    $min = $especialidad->edad_minima;
    $max = $especialidad->edad_maxima;
    $textoEdad = match (true) {
        $min === null && $max === null => 'Todas las edades',
        $max === null => "desde {$min} años",
        $min === null => "hasta {$max} años",
        default => "{$min} a {$max} años",
    };
    $textoSexo = match ($especialidad->sexo_permitido) { 'F' => 'Solo mujeres', 'M' => 'Solo hombres', default => 'Ambos' };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Especialidad: {{ $especialidad->nombre }}
            </h2>
            <a href="{{ route('especialidades.listar') }}" class="text-sm text-indigo-600 hover:underline">Volver a la lista</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Datos de la especialidad --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                    <div>
                        <dt class="font-medium text-gray-500">Nombre</dt>
                        <dd class="mt-1 text-gray-900">{{ $especialidad->nombre }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Estado</dt>
                        <dd class="mt-1"><x-estado :estado="$especialidad->estado" /></dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="font-medium text-gray-500">Descripción</dt>
                        <dd class="mt-1 text-gray-900">{{ $especialidad->descripcion ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Rango de edad</dt>
                        <dd class="mt-1 text-gray-900">{{ $textoEdad }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Sexo permitido</dt>
                        <dd class="mt-1 text-gray-900">{{ $textoSexo }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-gray-500">Duración sugerida</dt>
                        <dd class="mt-1 text-gray-900">{{ $especialidad->duracion_sugerida_min }} minutos</dd>
                    </div>
                </dl>
            </div>

            {{-- Médicos que la ejercen --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Médicos que la ejercen</h3>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-2">Código</th>
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Principal</th>
                                <th class="px-3 py-2">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($medicos as $medico)
                                <tr>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ $medico->codigo }}</td>
                                    <td class="px-3 py-3">{{ $medico->nombres }} {{ $medico->apellido_paterno }} {{ $medico->apellido_materno }}</td>
                                    <td class="px-3 py-3">{{ $medico->es_principal ? 'Sí' : 'No' }}</td>
                                    <td class="px-3 py-3"><x-estado :estado="$medico->estado" /></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="px-3 py-6 text-center text-gray-500">Ningún médico ejerce esta especialidad.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
