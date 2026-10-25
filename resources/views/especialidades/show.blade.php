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
        <x-encabezado ruta="Catálogos / Especialidades" :titulo="'Especialidad: ' . $especialidad->nombre">
            <a href="{{ route('especialidades.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            {{-- Datos de la especialidad --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Datos de la especialidad</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato">
                        <dt>Nombre</dt>
                        <dd>{{ $especialidad->nombre }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Estado</dt>
                        <dd><x-estado :estado="$especialidad->estado" /></dd>
                    </div>
                    <div class="dato xl:col-span-2">
                        <dt>Descripción</dt>
                        <dd>{{ $especialidad->descripcion ?? '—' }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Rango de edad</dt>
                        <dd>{{ $textoEdad }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Sexo permitido</dt>
                        <dd>{{ $textoSexo }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Duración sugerida</dt>
                        <dd>{{ $especialidad->duracion_sugerida_min }} minutos</dd>
                    </div>
                </dl>
            </div>

            {{-- Médicos que la ejercen --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Médicos que la ejercen</h2>

                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Principal</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($medicos as $medico)
                                <tr>
                                    <td class="whitespace-nowrap">{{ $medico->codigo }}</td>
                                    <td>{{ $medico->nombres }} {{ $medico->apellido_paterno }} {{ $medico->apellido_materno }}</td>
                                    <td>{{ $medico->es_principal ? 'Sí' : 'No' }}</td>
                                    <td><x-estado :estado="$medico->estado" /></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="py-8 text-center text-slate-500">Ningún médico ejerce esta especialidad.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
