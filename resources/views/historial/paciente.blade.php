{{-- CU20 Consultar historial de citas (RF12, §3.4): TODAS las citas del paciente (pasadas, canceladas y
     reasignadas), con filtros, totales por estado e indicadores de reprogramación y reemplazo.
     A1: el paciente elige entre sus citas y las de los menores a su cargo. --}}
@php
    $persona = $paciente->persona;
    $claseSelect = 'mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm';
    $origenes = ['AUTOSERVICIO' => 'Reserva en línea', 'RECEPCION' => 'Recepción'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $opciones->isNotEmpty() ? 'Mis citas' : 'Historial de citas' }}</h2>
            @can('pacientes.buscar')
                <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="text-sm text-indigo-600 hover:underline">Ficha del paciente</a>
            @endcan
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU20 A1: «Mis citas» / «Citas de <menor a mi cargo>» --}}
            @if ($opciones->count() > 1)
                <form method="GET" action="{{ route('historiales-cita.listar') }}" class="bg-white shadow-sm sm:rounded-lg p-6 max-w-md">
                    <x-input-label for="paciente_id" value="Ver" />
                    <select id="paciente_id" name="paciente_id" class="{{ $claseSelect }}" onchange="this.form.submit()">
                        @foreach ($opciones as $opcion)
                            <option value="{{ $opcion->id }}" @selected($opcion->id === $paciente->id)>
                                {{ $opcion->persona_id === auth()->user()->persona_id ? 'Mis citas' : 'Citas de ' . $opcion->persona->nombre_completo }}
                            </option>
                        @endforeach
                    </select>
                    <noscript><x-primary-button class="mt-3">Ver</x-primary-button></noscript>
                </form>
            @endif

            {{-- CU20 paso 2: datos del paciente --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="font-medium text-gray-500">Código</dt><dd>{{ $paciente->codigo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Paciente</dt><dd>{{ $persona->nombre_completo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Edad</dt><dd>{{ $persona->edad }} años</dd></div>
                </dl>
            </div>

            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                @if ($totalCitas === 0)
                    {{-- CU20 excepción --}}
                    <p class="text-sm text-gray-600">El paciente aún no tiene citas registradas.</p>
                @else
                    {{-- CU20 paso 3: filtros. novalidate: valida validarFiltrosHistorial() (resources/js/citas.js); el servidor valida igual. --}}
                    <form method="GET" action="{{ route('historiales-cita.listar') }}" novalidate class="grid grid-cols-1 gap-4 sm:grid-cols-5 items-end"
                          onsubmit="return validarFiltrosHistorial(this)">
                        <input type="hidden" name="paciente_id" value="{{ $paciente->id }}">
                        <div>
                            <x-input-label for="desde" value="Desde" />
                            <x-text-input id="desde" name="desde" type="date" class="mt-1 block w-full" :value="$filtros['desde']" />
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="desde" hidden></p>
                            <x-input-error :messages="$errores->get('desde')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="hasta" value="Hasta" />
                            <x-text-input id="hasta" name="hasta" type="date" class="mt-1 block w-full" :value="$filtros['hasta']" />
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="hasta" hidden></p>
                            <x-input-error :messages="$errores->get('hasta')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="especialidad_id" value="Especialidad" />
                            <select id="especialidad_id" name="especialidad_id" class="{{ $claseSelect }}">
                                <option value="">Todas</option>
                                @foreach ($especialidades as $especialidad)
                                    <option value="{{ $especialidad->id }}" @selected((string) $filtros['especialidad_id'] === (string) $especialidad->id)>{{ $especialidad->nombre }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errores->get('especialidad_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="estado" value="Estado" />
                            <select id="estado" name="estado" class="{{ $claseSelect }}">
                                <option value="">Todos</option>
                                @foreach ($estados as $valor)
                                    <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ ucfirst(mb_strtolower($valor)) }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errores->get('estado')" class="mt-2" />
                        </div>
                        <div class="flex items-center gap-3">
                            <x-primary-button>Filtrar</x-primary-button>
                            <a href="{{ route('historiales-cita.listar', ['paciente_id' => $paciente->id]) }}" class="text-sm text-indigo-600 hover:underline">Limpiar</a>
                        </div>
                    </form>

                    {{-- CU20 paso 3: totales por estado --}}
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        @foreach ($estados as $valor)
                            <span class="inline-flex items-center gap-1"><x-estado-cita :estado="$valor" /> {{ $totales[$valor] ?? 0 }}</span>
                        @endforeach
                    </div>

                    {{-- CU20 paso 2: de la más reciente a la más antigua --}}
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Código</th>
                                    <th class="px-3 py-2">Fecha y hora</th>
                                    <th class="px-3 py-2">Médico</th>
                                    <th class="px-3 py-2">Especialidad</th>
                                    <th class="px-3 py-2">Estado</th>
                                    <th class="px-3 py-2">Origen</th>
                                    <th class="px-3 py-2">Registrada el</th>
                                    @if ($autoservicio)
                                        <th class="px-3 py-2">Acciones</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($citas as $cita)
                                    @php
                                        $inicio = \Illuminate\Support\Carbon::parse($cita->fecha)->setTimeFromTimeString($cita->hora_inicio);
                                        // CU20 A1: citas futuras PROGRAMADA o CONFIRMADA (CU16 y CU17 en citas/show).
                                        $cambiable = $autoservicio && in_array($cita->estado, \App\Models\Cita::ESTADOS_VIGENTES, true) && $inicio->isFuture();
                                    @endphp
                                    <tr class="align-top">
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            {{-- CU20 paso 4: trazabilidad --}}
                                            <a href="{{ route('historiales-cita.mostrar', $cita->id) }}" class="text-indigo-600 hover:underline">{{ $cita->codigo }}</a>
                                            {{-- Indicadores de reprogramación (RN18) y reemplazo (CU16 A1) --}}
                                            @if ($cita->reprogramaciones > 0)
                                                <div class="text-xs text-gray-500">Reprogramada {{ $cita->reprogramaciones }} {{ $cita->reprogramaciones == 1 ? 'vez' : 'veces' }}</div>
                                            @endif
                                            @if ($cita->reemplaza_codigo)
                                                <div class="text-xs text-gray-500">Reemplaza a <a href="{{ route('historiales-cita.mostrar', $cita->cita_reemplazada_id) }}" class="hover:underline">{{ $cita->reemplaza_codigo }}</a></div>
                                            @endif
                                            @if ($cita->reemplazada_por_codigo)
                                                <div class="text-xs text-gray-500">Reemplazada por <a href="{{ route('historiales-cita.mostrar', $cita->reemplazada_por_id) }}" class="hover:underline">{{ $cita->reemplazada_por_codigo }}</a></div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $inicio->format('d/m/Y') }} {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</td>
                                        <td class="px-3 py-3">{{ $cita->medico_sexo === 'F' ? 'Dra.' : 'Dr.' }} {{ $cita->medico_nombres }} {{ $cita->medico_apellido }}</td>
                                        <td class="px-3 py-3">{{ $cita->especialidad }}</td>
                                        <td class="px-3 py-3"><x-estado-cita :estado="$cita->estado" /></td>
                                        <td class="px-3 py-3">{{ $origenes[$cita->origen] ?? ($cita->origen ?? '—') }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $cita->fecha_registro?->format('d/m/Y H:i') }}</td>
                                        @if ($autoservicio)
                                            <td class="px-3 py-3 whitespace-nowrap">
                                                @if ($cambiable)
                                                    <a href="{{ route('citas.mostrar', [$cita->id, 'accion' => 'reprogramar']) }}" class="text-xs font-semibold text-indigo-600 hover:underline">Reprogramar</a>
                                                    <a href="{{ route('citas.mostrar', [$cita->id, 'accion' => 'cancelar']) }}" class="ms-2 text-xs font-semibold text-red-700 hover:underline">Cancelar</a>
                                                @else
                                                    <span class="text-gray-400">—</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $autoservicio ? 8 : 7 }}" class="px-3 py-6 text-center text-gray-500">No hay citas con esos filtros.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$citas" />
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
