{{-- CU30 Consultar cardex (RF-31) paso 2: el médico elige entre «Mis pacientes» (con cita con él);
     el paciente con menores a cargo elige entre su cardex y el de ellos (como CU20 A1). --}}
@php
    $documento = fn ($persona) => $persona->numero_documento
        ? $persona->numero_documento . ($persona->complemento ? '-' . $persona->complemento : '')
        : 'Sin documento';
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Atención clínica" :titulo="$modo === 'medico' ? 'Mis pacientes' : 'Mi cardex'" />
    </x-slot>

    <div class="space-y-6">
        @if ($modo === 'medico')
            {{-- CU30 paso 2: buscador como CU11 --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Filtros</h2>
                <form method="GET" action="{{ route('historias-clinicas.listar') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <x-input-label for="q" value="Documento, código o nombre" />
                        <x-text-input id="q" name="q" type="search" :value="$texto" placeholder="Por ejemplo, PAC-000001 o Mamani" />
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button>Buscar</x-primary-button>
                        @if ($texto !== '')
                            <a href="{{ route('historias-clinicas.listar') }}" class="btn-secundario">Limpiar</a>
                        @endif
                    </div>
                </form>
            </div>

            {{-- CU30 paso 2: pacientes que tienen o tuvieron cita con el médico --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Pacientes con cita conmigo</h2>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Nombre</th>
                                <th>Documento</th>
                                <th>Edad</th>
                                <th>Estado</th>
                                <th class="text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($pacientes as $paciente)
                                <tr>
                                    <td class="whitespace-nowrap font-semibold text-marino-900">{{ $paciente->codigo }}</td>
                                    <td>{{ $paciente->persona->nombre_completo }}</td>
                                    <td class="whitespace-nowrap">{{ $documento($paciente->persona) }}</td>
                                    <td class="whitespace-nowrap">{{ $paciente->persona->edad }} años</td>
                                    <td><x-estado :estado="$paciente->estado" /></td>
                                    <td>
                                        <div class="acciones">
                                            <a href="{{ route('historias-clinicas.mostrar', $paciente->id) }}" class="accion">Ver cardex</a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="py-8 text-center text-slate-500">
                                        {{ $texto !== '' ? 'No se encontraron pacientes con ese dato.' : 'Aún no tiene pacientes con cita.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <x-paginacion :paginador="$pacientes" />
            </div>
        @else
            {{-- CU30 paso 2 (paciente): «Mi cardex» y los menores a su cargo --}}
            <div class="tarjeta max-w-2xl space-y-4">
                <h2 class="tarjeta-titulo">¿Qué cardex desea ver?</h2>
                <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200">
                    @foreach ($opciones as $opcion)
                        <li class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                            <span>
                                <span class="font-semibold text-marino-900">
                                    {{ $opcion->persona_id === auth()->user()->persona_id ? 'Mi cardex' : $opcion->persona->nombre_completo . ' (menor a mi cargo)' }}
                                </span>
                                <span class="text-slate-500">· {{ $opcion->codigo }}</span>
                            </span>
                            <a href="{{ route('historias-clinicas.mostrar', $opcion->id) }}" class="accion">Ver cardex</a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>
</x-app-layout>
