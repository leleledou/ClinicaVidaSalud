{{-- CU19 Consultar agenda médica (RF11, §3.2, §3.3): fichas del día de un médico con sus citas
     (también las canceladas), totales por estado y los botones de CU18 (accionesPermitidas). --}}
@php
    $claseSelect = 'mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm';
    // CU19 paso 3: los botones de día conservan el médico y el estado.
    $irA = fn ($dia) => route('citas.agenda', array_filter(['medico_id' => $todos ? $filtros['medico_id'] : null, 'fecha' => $dia->toDateString(), 'estado' => $filtros['estado']]));
    // CU19 paso 5 (RF11, §3.3): pendientes en azul, atendidas en verde, canceladas en gris tachado,
    // inasistencias en rojo, libres en blanco y bloqueadas en gris claro.
    $colorFila = fn ($tipo) => match ($tipo) {
        'PROGRAMADA', 'CONFIRMADA' => 'bg-blue-50',
        'ATENDIDA' => 'bg-green-50',
        'CANCELADA' => 'bg-gray-50 text-gray-500 line-through',
        'INASISTENCIA' => 'bg-red-50',
        'BLOQUEADA' => 'bg-gray-100 text-gray-600',
        default => 'bg-white',
    };
    $etiquetas = ['PROGRAMADA' => 'Programadas', 'CONFIRMADA' => 'Confirmadas', 'ATENDIDA' => 'Atendidas', 'CANCELADA' => 'Canceladas',
        'INASISTENCIA' => 'Inasistencias', 'LIBRE' => 'Libres', 'BLOQUEADA' => 'Bloqueadas'];
    $colorTotal = fn ($tipo) => match ($tipo) {
        'PROGRAMADA', 'CONFIRMADA' => 'border-blue-200 bg-blue-50 text-blue-800',
        'ATENDIDA' => 'border-green-200 bg-green-50 text-green-800',
        'CANCELADA' => 'border-gray-200 bg-gray-50 text-gray-600',
        'INASISTENCIA' => 'border-red-200 bg-red-50 text-red-800',
        'BLOQUEADA' => 'border-gray-300 bg-gray-100 text-gray-700',
        default => 'border-gray-200 bg-white text-gray-700',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $todos ? 'Agenda médica' : 'Mi agenda' }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Regla de negocio que impidió un cambio de estado (CU18) --}}
            @if ($errors->has('cita'))
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">{{ $errors->first('cita') }}</div>
            @endif

            {{-- CU19 paso 3: filtros. novalidate: valida validarFiltrosAgenda() (resources/js/citas.js); el servidor valida igual. --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <form method="GET" action="{{ route('citas.agenda') }}" novalidate class="grid grid-cols-1 gap-4 sm:grid-cols-4 items-end"
                      onsubmit="return validarFiltrosAgenda(this, {{ Js::from($todos) }})">
                    @if ($todos)
                        {{-- CU19 paso 2: médicos ACTIVOS e INACTIVOS (sus citas históricas se consultan igual) --}}
                        <div class="sm:col-span-2">
                            <x-input-label for="medico_id" value="Médico" />
                            <select id="medico_id" name="medico_id" class="{{ $claseSelect }}">
                                <option value="">Seleccione…</option>
                                @foreach ($medicos as $m)
                                    <option value="{{ $m->id }}" @selected((string) $filtros['medico_id'] === (string) $m->id)>
                                        {{ $m->sexo === 'F' ? 'Dra.' : 'Dr.' }} {{ $m->nombres }} {{ $m->apellido_paterno }} ({{ $m->codigo }}){{ $m->estado !== 'ACTIVO' ? ' — INACTIVO' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="medico_id" hidden></p>
                            <x-input-error :messages="$errores->get('medico_id')" class="mt-2" />
                        </div>
                    @else
                        <div class="sm:col-span-2 text-sm text-gray-700">
                            <span class="font-medium text-gray-500">Médico:</span>
                            {{ \App\Services\CitaService::tituloMedico($medico->persona) }} ({{ $medico->codigo }})
                        </div>
                    @endif
                    <div>
                        <x-input-label for="fecha" value="Fecha" />
                        <x-text-input id="fecha" name="fecha" type="date" class="mt-1 block w-full" :value="$fecha->toDateString()" />
                        <p class="js-error mt-2 text-sm text-red-600" data-campo="fecha" hidden></p>
                        <x-input-error :messages="$errores->get('fecha')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="estado" value="Estado de la cita" />
                        <select id="estado" name="estado" class="{{ $claseSelect }}">
                            <option value="">Todas</option>
                            @foreach ($estados as $valor)
                                <option value="{{ $valor }}" @selected($filtros['estado'] === $valor)>{{ ucfirst(mb_strtolower($valor)) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errores->get('estado')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-4 flex flex-wrap items-center gap-2">
                        <x-primary-button>Consultar</x-primary-button>
                        {{-- CU19 paso 3: «Día anterior», «Hoy» y «Día siguiente» --}}
                        <a href="{{ $irA($fecha->copy()->subDay()) }}" class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">← Día anterior</a>
                        <a href="{{ $irA(today()) }}" class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">Hoy</a>
                        <a href="{{ $irA($fecha->copy()->addDay()) }}" class="inline-flex items-center px-3 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">Día siguiente →</a>
                    </div>
                </form>
            </div>

            @if ($medico === null)
                <div class="bg-white shadow-sm sm:rounded-lg p-6 text-sm text-gray-600">Elija el médico para ver su agenda.</div>
            @else
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-lg font-medium text-gray-900">
                            {{ \App\Services\CitaService::tituloMedico($medico->persona) }}
                            @if ($medico->estado !== 'ACTIVO') <span class="ms-1 text-xs font-semibold text-red-700">INACTIVO</span> @endif
                        </h3>
                        <p class="text-sm text-gray-600">{{ \App\Services\AgendaService::DIAS[$fecha->dayOfWeekIso] }} {{ $fecha->format('d/m/Y') }}</p>
                    </div>

                    @if ($mensaje)
                        {{-- CU19 excepción: no atiende ese día; los días en que sí atiende --}}
                        <div class="rounded-md bg-yellow-50 p-4 text-sm text-yellow-800 space-y-2">
                            <p class="font-medium">{{ $mensaje }}</p>
                            @forelse ($diasAtencion as $especialidad => $texto)
                                <p>{{ $especialidad }}: atiende {{ $texto }}.</p>
                            @empty
                                <p>El médico no tiene horarios de atención vigentes.</p>
                            @endforelse
                        </div>
                    @else
                        {{-- CU19 paso 5: totales del día por estado --}}
                        <div class="flex flex-wrap gap-2">
                            @foreach ($etiquetas as $tipo => $texto)
                                <span class="rounded-md border px-3 py-1 text-xs font-semibold {{ $colorTotal($tipo) }}">{{ $texto }}: {{ $totales[$tipo] ?? 0 }}</span>
                            @endforeach
                        </div>

                        {{-- CU19 paso 4: fichas del día en orden de hora --}}
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 text-sm">
                                <thead>
                                    <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                        <th class="px-3 py-2">Hora</th>
                                        <th class="px-3 py-2">Código</th>
                                        <th class="px-3 py-2">Paciente</th>
                                        <th class="px-3 py-2">Especialidad</th>
                                        <th class="px-3 py-2">Estado</th>
                                        <th class="px-3 py-2">Motivo</th>
                                        <th class="px-3 py-2">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @forelse ($filas as $fila)
                                        @php($cita = $fila['cita'])
                                        <tr class="{{ $colorFila($fila['tipo']) }}">
                                            <td class="px-3 py-3 whitespace-nowrap">{{ substr($fila['ficha']->hora_inicio, 0, 5) }}–{{ substr($fila['ficha']->hora_fin, 0, 5) }}</td>
                                            @if ($cita)
                                                <td class="px-3 py-3 whitespace-nowrap"><a href="{{ route('citas.mostrar', $cita->id) }}" class="text-indigo-600 hover:underline">{{ $cita->codigo }}</a></td>
                                                <td class="px-3 py-3">
                                                    {{ $cita->paciente->persona->nombre_completo }}
                                                    <span class="text-gray-500">({{ $cita->paciente->codigo }}, {{ $cita->paciente->persona->edad }} años)</span>
                                                </td>
                                                <td class="px-3 py-3">{{ $fila['ficha']->especialidad }}</td>
                                                <td class="px-3 py-3 no-underline"><x-estado-cita :estado="$cita->estado" /></td>
                                                {{-- CU19 A1: «Reservado» si el usuario no puede ver el motivo --}}
                                                <td class="px-3 py-3 {{ $fila['motivo'] === 'Reservado' ? 'italic text-gray-400' : '' }}">{{ \Illuminate\Support\Str::limit($fila['motivo'], 60) }}</td>
                                                <td class="px-3 py-3">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        @include('citas._estado-botones', ['cita' => $cita, 'acciones' => $fila['acciones']])
                                                        <a href="{{ route('citas.mostrar', $cita->id) }}" class="text-xs font-semibold text-indigo-600 hover:underline">Ver cita</a>
                                                    </div>
                                                </td>
                                            @else
                                                <td class="px-3 py-3 font-medium" colspan="2">
                                                    {{ $fila['tipo'] === 'BLOQUEADA' ? 'Bloqueada' : 'Libre' }}
                                                    @if ($fila['tipo'] === 'BLOQUEADA' && $fila['ficha']->motivo_bloqueo)
                                                        <span class="font-normal text-gray-500">({{ $fila['ficha']->motivo_bloqueo }})</span>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-3">{{ $fila['ficha']->especialidad }}</td>
                                                <td class="px-3 py-3" colspan="3"></td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">No hay citas en ese estado en la fecha seleccionada.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
