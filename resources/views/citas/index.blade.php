{{-- Citas de una fecha (por defecto hoy) con filtro por estado, ordenadas por hora. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Citas</h2>
            @can('citas.programar')
                <a href="{{ route('citas.crear') }}" class="text-sm text-indigo-600 hover:underline">Programar cita</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <form method="GET" action="{{ route('citas.listar') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="fecha" value="Fecha" />
                        <x-text-input id="fecha" name="fecha" type="date" class="mt-1 block" :value="$fecha->toDateString()" />
                    </div>
                    <div>
                        <x-input-label for="estado" value="Estado" />
                        <select id="estado" name="estado" class="mt-1 block border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">Todos</option>
                            @foreach ($estados as $valor)
                                <option value="{{ $valor }}" @selected($estado === $valor)>{{ $valor }}</option>
                            @endforeach
                        </select>
                    </div>
                    <x-primary-button>Filtrar</x-primary-button>
                </form>

                <p class="text-sm text-gray-600">{{ \App\Services\AgendaService::DIAS[$fecha->dayOfWeekIso] }} {{ $fecha->format('d/m/Y') }}</p>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-2">Hora</th>
                                <th class="px-3 py-2">Código</th>
                                <th class="px-3 py-2">Paciente</th>
                                <th class="px-3 py-2">Especialidad</th>
                                <th class="px-3 py-2">Médico</th>
                                <th class="px-3 py-2">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($citas as $cita)
                                <tr>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap"><a href="{{ route('citas.mostrar', $cita->id) }}" class="text-indigo-600 hover:underline">{{ $cita->codigo }}</a></td>
                                    <td class="px-3 py-3">
                                        <a href="{{ route('citas.mostrar', $cita->id) }}" class="hover:underline">{{ $cita->paciente_nombres }} {{ $cita->paciente_apellido }}</a>
                                        <span class="text-gray-500">({{ $cita->paciente_codigo }})</span>
                                    </td>
                                    <td class="px-3 py-3">{{ $cita->especialidad }}</td>
                                    <td class="px-3 py-3">{{ $cita->medico_sexo === 'F' ? 'Dra.' : 'Dr.' }} {{ $cita->medico_nombres }} {{ $cita->medico_apellido }}</td>
                                    <td class="px-3 py-3"><x-estado-cita :estado="$cita->estado" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">No hay citas en esa fecha</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <x-paginacion :paginador="$citas" />
            </div>
        </div>
    </div>
</x-app-layout>
