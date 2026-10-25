{{-- CU08: ficha de un consultorio con sus horarios de atención. --}}
@php
    $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    $fecha = fn ($valor) => \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Catálogos / Consultorios" :titulo="'Consultorio: ' . $consultorio->nombre">
            <a href="{{ route('consultorios.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            {{-- Datos del consultorio --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Datos del consultorio</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato">
                        <dt>Nombre</dt>
                        <dd>{{ $consultorio->nombre }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Ubicación</dt>
                        <dd>{{ $consultorio->ubicacion }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Estado</dt>
                        <dd><x-estado :estado="$consultorio->estado" /></dd>
                    </div>
                </dl>
            </div>

            {{-- Horarios del consultorio --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Horarios de atención</h2>

                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Médico</th>
                                <th>Especialidad</th>
                                <th>Día</th>
                                <th>Horario</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($horarios as $horario)
                                <tr>
                                    <td>
                                        <span class="text-slate-500">{{ $horario->medico_codigo }}</span>
                                        {{ $horario->nombres }} {{ $horario->apellido_paterno }} {{ $horario->apellido_materno }}
                                    </td>
                                    <td>{{ $horario->especialidad }}</td>
                                    <td>{{ $dias[$horario->dia_semana] ?? $horario->dia_semana }}</td>
                                    <td class="whitespace-nowrap">{{ substr($horario->hora_inicio, 0, 5) }} – {{ substr($horario->hora_fin, 0, 5) }}</td>
                                    <td class="whitespace-nowrap">
                                        Desde {{ $fecha($horario->vigente_desde) }}
                                        {{ $horario->vigente_hasta ? 'hasta ' . $fecha($horario->vigente_hasta) : '(sin fecha de fin)' }}
                                    </td>
                                    <td><x-estado :estado="$horario->estado" /></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-slate-500">El consultorio no tiene horarios asignados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
