{{-- Citas de una fecha (por defecto hoy) con filtro por estado, ordenadas por hora.
     CU18 pasos 1 y 2: cada cita muestra solo los botones válidos ($acciones = accionesPermitidas).
     CU17 paso 2: «Cancelar» abre el formulario con el motivo en la misma fila.
     CU18 A2: con $soloMias, el médico ve solo sus citas («Mis citas de hoy»). --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $soloMias ? 'Mis citas' : 'Citas' }}</h2>
            @can('citas.programar')
                <a href="{{ route('citas.crear') }}" class="text-sm text-indigo-600 hover:underline">Programar cita</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                {{-- Regla de negocio que impidió el cambio (RF08–RF10, RN21) --}}
                @if ($errors->has('cita'))
                    <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">{{ $errors->first('cita') }}</div>
                @endif

                <form method="GET" action="{{ route('citas.listar') }}" class="flex flex-wrap items-end gap-3">
                    @if ($soloMias)
                        <input type="hidden" name="mias" value="1">
                    @endif
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
                                <th class="px-3 py-2">Acciones</th>
                            </tr>
                        </thead>
                        @forelse ($citas as $cita)
                            @php
                                $accionesCita = $acciones[$cita->id] ?? [];
                                $cambioFicha = array_intersect(['reprogramar', 'reasignar'], $accionesCita);
                            @endphp
                            <tbody class="border-b border-gray-100"
                                   x-data="{ cancelar: {{ Js::from(old('accion') === 'cancelar' && (string) old('id') === (string) $cita->id) }} }">
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
                                    <td class="px-3 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            @include('citas._estado-botones', ['cita' => $cita, 'acciones' => $accionesCita])
                                            {{-- CU16: reprogramar y reasignar se hacen en el detalle (elegir la nueva ficha) --}}
                                            @if ($cambioFicha)
                                                <a href="{{ route('citas.mostrar', $cita->id) }}" class="text-xs font-semibold text-indigo-600 hover:underline">Reprogramar</a>
                                            @endif
                                            @if (in_array('cancelar', $accionesCita, true))
                                                <button type="button" class="text-xs font-semibold text-red-700 hover:underline" @click="cancelar = ! cancelar">Cancelar</button>
                                            @endif
                                            @if (! $accionesCita)
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                                {{-- CU17 pasos 2 y 3: formulario de cancelación de esta cita --}}
                                @if (in_array('cancelar', $accionesCita, true))
                                    <tr x-show="cancelar" x-cloak>
                                        <td colspan="7" class="px-3 pb-4">
                                            <div class="rounded-md bg-gray-50 p-4 max-w-2xl">
                                                <p class="mb-2 text-sm font-medium text-gray-900">Cancelar la cita {{ $cita->codigo }}</p>
                                                @include('citas._cancelar', ['cita' => $cita])
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            </tbody>
                        @empty
                            <tbody>
                                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">No hay citas en esa fecha</td></tr>
                            </tbody>
                        @endforelse
                    </table>
                </div>

                <x-paginacion :paginador="$citas" />
            </div>
        </div>
    </div>
</x-app-layout>
