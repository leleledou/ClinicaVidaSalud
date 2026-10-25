{{-- Citas de una fecha (por defecto hoy) con filtro por estado, ordenadas por hora.
     CU18 pasos 1 y 2: cada cita muestra solo los botones válidos ($acciones = accionesPermitidas).
     CU17 paso 2: «Cancelar» abre el formulario con el motivo en la misma fila.
     CU18 A2: con $soloMias, el médico ve solo sus citas («Mis citas de hoy»). --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Citas / Lista" :titulo="$soloMias ? 'Mis citas' : 'Citas'">
            @can('citas.programar')
                <a href="{{ route('citas.crear') }}" class="font-semibold text-teal-700 hover:underline">Programar cita</a>
            @endcan
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        {{-- Regla de negocio que impidió el cambio (RF08–RF10, RN21) --}}
        @if ($errors->has('cita'))
            <div class="alerta-error font-medium">{{ $errors->first('cita') }}</div>
        @endif

        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Filtros</h2>
            <form method="GET" action="{{ route('citas.listar') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @if ($soloMias)
                    <input type="hidden" name="mias" value="1">
                @endif
                <div>
                    <x-input-label for="fecha" value="Fecha" />
                    <x-text-input id="fecha" name="fecha" type="date" :value="$fecha->toDateString()" />
                </div>
                <div>
                    <x-input-label for="estado" value="Estado" />
                    <select id="estado" name="estado" class="campo">
                        <option value="">Todos</option>
                        @foreach ($estados as $valor)
                            <option value="{{ $valor }}" @selected($estado === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-primary-button>Filtrar</x-primary-button>
                </div>
            </form>
        </div>

        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">{{ \App\Services\AgendaService::DIAS[$fecha->dayOfWeekIso] }} {{ $fecha->format('d/m/Y') }}</h2>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Código</th>
                            <th>Paciente</th>
                            <th>Especialidad</th>
                            <th>Médico</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    @forelse ($citas as $cita)
                        @php
                            $accionesCita = $acciones[$cita->id] ?? [];
                            $cambioFicha = array_intersect(['reprogramar', 'reasignar'], $accionesCita);
                        @endphp
                        <tbody class="border-b border-slate-100"
                               x-data="{ cancelar: {{ Js::from(old('accion') === 'cancelar' && (string) old('id') === (string) $cita->id) }} }">
                            <tr>
                                <td class="whitespace-nowrap font-semibold text-marino-900">{{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</td>
                                <td class="whitespace-nowrap"><a href="{{ route('citas.mostrar', $cita->id) }}" class="font-semibold text-teal-700 hover:underline">{{ $cita->codigo }}</a></td>
                                <td>
                                    <a href="{{ route('citas.mostrar', $cita->id) }}" class="font-medium text-marino-900 hover:text-teal-700 hover:underline">{{ $cita->paciente_nombres }} {{ $cita->paciente_apellido }}</a>
                                    <span class="text-slate-500">({{ $cita->paciente_codigo }})</span>
                                </td>
                                <td>{{ $cita->especialidad }}</td>
                                <td>{{ $cita->medico_sexo === 'F' ? 'Dra.' : 'Dr.' }} {{ $cita->medico_nombres }} {{ $cita->medico_apellido }}</td>
                                <td><x-estado-cita :estado="$cita->estado" /></td>
                                <td>
                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                        @include('citas._estado-botones', ['cita' => $cita, 'acciones' => $accionesCita])
                                        {{-- CU16: reprogramar y reasignar se hacen en el detalle (elegir la nueva ficha) --}}
                                        @if ($cambioFicha)
                                            <a href="{{ route('citas.mostrar', $cita->id) }}" class="accion">Reprogramar</a>
                                        @endif
                                        @if (in_array('cancelar', $accionesCita, true))
                                            <button type="button" class="accion-peligro" @click="cancelar = ! cancelar">Cancelar</button>
                                        @endif
                                        @if (! $accionesCita)
                                            <span class="text-slate-400">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            {{-- CU17 pasos 2 y 3: formulario de cancelación de esta cita --}}
                            @if (in_array('cancelar', $accionesCita, true))
                                <tr x-show="cancelar" x-cloak>
                                    <td colspan="7" class="pb-4">
                                        <div class="max-w-2xl rounded-xl border border-red-200 bg-red-50/50 p-4">
                                            <p class="mb-2 text-sm font-semibold text-marino-900">Cancelar la cita {{ $cita->codigo }}</p>
                                            @include('citas._cancelar', ['cita' => $cita])
                                        </div>
                                    </td>
                                </tr>
                            @endif
                        </tbody>
                    @empty
                        <tbody>
                            <tr><td colspan="7" class="py-8 text-center text-slate-500">No hay citas en esa fecha</td></tr>
                        </tbody>
                    @endforelse
                </table>
            </div>

            <x-paginacion :paginador="$citas" />
        </div>
    </div>
</x-app-layout>
