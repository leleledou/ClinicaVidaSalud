{{-- CU19 Consultar agenda médica (RF11, §3.2, §3.3): fichas del día de un médico con sus citas
     (también las canceladas), totales por estado y los botones de CU18 (accionesPermitidas). --}}
@php
    $claseSelect = 'campo';
    // CU19 paso 3: los botones de día conservan el médico y el estado.
    $irA = fn ($dia) => route('citas.agenda', array_filter(['medico_id' => $todos ? $filtros['medico_id'] : null, 'fecha' => $dia->toDateString(), 'estado' => $filtros['estado']]));
    // CU19 paso 5 (RF11, §3.3): pendientes en azul, atendidas en verde, canceladas en gris tachado,
    // inasistencias en rojo, libres en blanco y bloqueadas en gris claro.
    $colorFila = fn ($tipo) => match ($tipo) {
        'PROGRAMADA', 'CONFIRMADA' => 'bg-blue-50',
        'ATENDIDA' => 'bg-green-50',
        'CANCELADA' => 'bg-slate-50 text-slate-500 line-through',
        'INASISTENCIA' => 'bg-red-50',
        'BLOQUEADA' => 'bg-slate-100 text-slate-600',
        default => 'bg-white',
    };
    $etiquetas = ['PROGRAMADA' => 'Programadas', 'CONFIRMADA' => 'Confirmadas', 'ATENDIDA' => 'Atendidas', 'CANCELADA' => 'Canceladas',
        'INASISTENCIA' => 'Inasistencias', 'LIBRE' => 'Libres', 'BLOQUEADA' => 'Bloqueadas'];
    $colorTotal = fn ($tipo) => match ($tipo) {
        'PROGRAMADA', 'CONFIRMADA' => 'border-blue-200 bg-blue-50 text-blue-800',
        'ATENDIDA' => 'border-green-200 bg-green-50 text-green-800',
        'CANCELADA' => 'border-slate-200 bg-slate-50 text-slate-600',
        'INASISTENCIA' => 'border-red-200 bg-red-50 text-red-800',
        'BLOQUEADA' => 'border-slate-300 bg-slate-100 text-slate-700',
        default => 'border-slate-200 bg-white text-slate-700',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Citas / Agenda" :titulo="$todos ? 'Agenda médica' : 'Mi agenda'" />
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            {{-- Regla de negocio que impidió un cambio de estado (CU18) --}}
            @if ($errors->has('cita'))
                <div class="alerta-error font-medium">{{ $errors->first('cita') }}</div>
            @endif

            {{-- CU19 paso 3: filtros. novalidate: valida validarFiltrosAgenda() (resources/js/citas.js); el servidor valida igual. --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Filtros</h2>
                <form method="GET" action="{{ route('citas.agenda') }}" novalidate class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-4"
                      onsubmit="return validarFiltrosAgenda(this, {{ Js::from($todos) }})">
                    @if ($todos)
                        {{-- CU19 paso 2: médicos ACTIVOS e INACTIVOS (sus citas históricas se consultan igual) --}}
                        <div class="sm:col-span-2 lg:col-span-1">
                            <x-input-label for="medico_id" value="Médico" />
                            <select id="medico_id" name="medico_id" class="{{ $claseSelect }}">
                                <option value="">Seleccione…</option>
                                @foreach ($medicos as $m)
                                    <option value="{{ $m->id }}" @selected((string) $filtros['medico_id'] === (string) $m->id)>
                                        {{ $m->sexo === 'F' ? 'Dra.' : 'Dr.' }} {{ $m->nombres }} {{ $m->apellido_paterno }} ({{ $m->codigo }}){{ $m->estado !== 'ACTIVO' ? ' — INACTIVO' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="js-error" data-campo="medico_id" hidden></p>
                            <x-input-error :messages="$errores->get('medico_id')" class="mt-2" />
                        </div>
                    @else
                        <div class="text-sm text-slate-700 sm:col-span-2 lg:col-span-1">
                            <span class="etiqueta">Médico:</span>
                            {{ \App\Services\CitaService::tituloMedico($medico->persona) }} ({{ $medico->codigo }})
                        </div>
                    @endif
                    <div>
                        <x-input-label for="fecha" value="Fecha" />
                        <x-text-input id="fecha" name="fecha" type="date" :value="$fecha->toDateString()" />
                        <p class="js-error" data-campo="fecha" hidden></p>
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
                    <div class="flex flex-wrap items-center gap-2 sm:col-span-2 lg:col-span-1">
                        <x-primary-button>Consultar</x-primary-button>
                        {{-- CU19 paso 3: «Día anterior», «Hoy» y «Día siguiente» --}}
                        <a href="{{ $irA($fecha->copy()->subDay()) }}" class="btn-secundario px-3">← Día anterior</a>
                        <a href="{{ $irA(today()) }}" class="btn-secundario px-3">Hoy</a>
                        <a href="{{ $irA($fecha->copy()->addDay()) }}" class="btn-secundario px-3">Día siguiente →</a>
                    </div>
                </form>
            </div>

            @if ($medico === null)
                <div class="alerta-info">Elija el médico para ver su agenda.</div>
            @else
                <div class="tarjeta space-y-4">
                    <div>
                        <h2 class="tarjeta-titulo !mb-1">Agenda del {{ \App\Services\AgendaService::DIAS[$fecha->dayOfWeekIso] }} {{ $fecha->format('d/m/Y') }}</h2>
                        <p class="text-sm font-medium text-slate-600">
                            {{ \App\Services\CitaService::tituloMedico($medico->persona) }}
                            @if ($medico->estado !== 'ACTIVO') <span class="insignia insignia-inactivo ms-1">INACTIVO</span> @endif
                        </p>
                    </div>

                    @if ($mensaje)
                        {{-- CU19 excepción: no atiende ese día; los días en que sí atiende --}}
                        <div class="alerta-aviso space-y-2">
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
                                <span class="rounded-full border px-3 py-1 text-xs font-semibold {{ $colorTotal($tipo) }}">{{ $texto }}: {{ $totales[$tipo] ?? 0 }}</span>
                            @endforeach
                        </div>

                        {{-- CU19 paso 4: fichas del día en orden de hora --}}
                        <div class="tabla-contenedor">
                            <table class="tabla">
                                <thead>
                                    <tr>
                                        <th>Hora</th>
                                        <th>Código</th>
                                        <th>Paciente</th>
                                        <th>Especialidad</th>
                                        <th>Estado</th>
                                        <th>Motivo</th>
                                        <th class="text-right">Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($filas as $fila)
                                        @php($cita = $fila['cita'])
                                        <tr class="{{ $colorFila($fila['tipo']) }}">
                                            <td class="whitespace-nowrap font-semibold text-marino-900">{{ substr($fila['ficha']->hora_inicio, 0, 5) }}–{{ substr($fila['ficha']->hora_fin, 0, 5) }}</td>
                                            @if ($cita)
                                                <td class="whitespace-nowrap"><a href="{{ route('citas.mostrar', $cita->id) }}" class="font-semibold text-teal-700 hover:underline">{{ $cita->codigo }}</a></td>
                                                <td>
                                                    {{ $cita->paciente->persona->nombre_completo }}
                                                    <span class="text-slate-500">({{ $cita->paciente->codigo }}, {{ $cita->paciente->persona->edad }} años)</span>
                                                </td>
                                                <td>{{ $fila['ficha']->especialidad }}</td>
                                                <td class="no-underline"><x-estado-cita :estado="$cita->estado" /></td>
                                                {{-- CU19 A1: «Reservado» si el usuario no puede ver el motivo --}}
                                                <td class="{{ $fila['motivo'] === 'Reservado' ? 'italic text-slate-400' : '' }}">{{ \Illuminate\Support\Str::limit($fila['motivo'], 60) }}</td>
                                                <td>
                                                    <div class="flex flex-wrap items-center justify-end gap-2">
                                                        @include('citas._estado-botones', ['cita' => $cita, 'acciones' => $fila['acciones']])
                                                        <a href="{{ route('citas.mostrar', $cita->id) }}" class="accion">Ver cita</a>
                                                    </div>
                                                </td>
                                            @else
                                                <td class="font-medium" colspan="2">
                                                    {{ $fila['tipo'] === 'BLOQUEADA' ? 'Bloqueada' : 'Libre' }}
                                                    @if ($fila['tipo'] === 'BLOQUEADA' && $fila['ficha']->motivo_bloqueo)
                                                        <span class="font-normal text-slate-500">({{ $fila['ficha']->motivo_bloqueo }})</span>
                                                    @endif
                                                </td>
                                                <td>{{ $fila['ficha']->especialidad }}</td>
                                                <td><x-estado-cita :estado="$fila['tipo'] === 'BLOQUEADA' ? 'BLOQUEADA' : 'DISPONIBLE'" /></td>
                                                <td colspan="2"></td>
                                            @endif
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="py-8 text-center text-slate-500">No hay citas en ese estado en la fecha seleccionada.</td></tr>
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
