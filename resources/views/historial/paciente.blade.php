{{-- CU20 Consultar historial de citas (RF12, §3.4): TODAS las citas del paciente (pasadas, canceladas y
     reasignadas), con filtros, totales por estado e indicadores de reprogramación y reemplazo.
     A1: el paciente elige entre sus citas y las de los menores a su cargo. --}}
@php
    $persona = $paciente->persona;
    $claseSelect = 'campo';
    $origenes = ['AUTOSERVICIO' => 'Reserva en línea', 'RECEPCION' => 'Recepción'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Citas / Historial" :titulo="$opciones->isNotEmpty() ? 'Mis citas' : 'Historial de citas'">
            @can('pacientes.buscar')
                <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="font-semibold text-teal-700 hover:underline">Ficha del paciente</a>
            @endcan
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            {{-- CU20 A1: «Mis citas» / «Citas de <menor a mi cargo>» --}}
            @if ($opciones->count() > 1)
                <form method="GET" action="{{ route('historiales-cita.listar') }}" class="tarjeta max-w-md">
                    <x-input-label for="paciente_id" value="Ver" class="!mb-1" />
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
            <div class="tarjeta">
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Código</dt><dd>{{ $paciente->codigo }}</dd></div>
                    <div class="dato"><dt>Paciente</dt><dd>{{ $persona->nombre_completo }}</dd></div>
                    <div class="dato"><dt>Edad</dt><dd>{{ $persona->edad }} años</dd></div>
                </dl>
            </div>

            @if ($totalCitas === 0)
                {{-- CU20 excepción --}}
                <div class="alerta-info">El paciente aún no tiene citas registradas.</div>
            @else
                <div class="tarjeta">
                    <h2 class="tarjeta-titulo">Filtros</h2>
                    {{-- CU20 paso 3: filtros. novalidate: valida validarFiltrosHistorial() (resources/js/citas.js); el servidor valida igual. --}}
                    <form method="GET" action="{{ route('historiales-cita.listar') }}" novalidate class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-5"
                          onsubmit="return validarFiltrosHistorial(this)">
                        <input type="hidden" name="paciente_id" value="{{ $paciente->id }}">
                        <div>
                            <x-input-label for="desde" value="Desde" />
                            <x-text-input id="desde" name="desde" type="date" :value="$filtros['desde']" />
                            <p class="js-error" data-campo="desde" hidden></p>
                            <x-input-error :messages="$errores->get('desde')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="hasta" value="Hasta" />
                            <x-text-input id="hasta" name="hasta" type="date" :value="$filtros['hasta']" />
                            <p class="js-error" data-campo="hasta" hidden></p>
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
                            <a href="{{ route('historiales-cita.listar', ['paciente_id' => $paciente->id]) }}" class="btn-secundario">Limpiar</a>
                        </div>
                    </form>
                </div>

                <div class="tarjeta space-y-4">
                    <h2 class="tarjeta-titulo">Citas</h2>
                    {{-- CU20 paso 3: totales por estado --}}
                    <div class="flex flex-wrap items-center gap-3 text-sm font-semibold text-marino-900">
                        @foreach ($estados as $valor)
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 py-0.5 pe-3 ps-0.5"><x-estado-cita :estado="$valor" /> {{ $totales[$valor] ?? 0 }}</span>
                        @endforeach
                    </div>

                    {{-- CU20 paso 2: de la más reciente a la más antigua --}}
                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Fecha y hora</th>
                                    <th>Médico</th>
                                    <th>Especialidad</th>
                                    <th>Estado</th>
                                    <th>Origen</th>
                                    <th>Registrada el</th>
                                    @if ($autoservicio)
                                        <th class="text-right">Acciones</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($citas as $cita)
                                    @php
                                        $inicio = \Illuminate\Support\Carbon::parse($cita->fecha)->setTimeFromTimeString($cita->hora_inicio);
                                        // CU20 A1: citas futuras PROGRAMADA o CONFIRMADA (CU16 y CU17 en citas/show).
                                        $cambiable = $autoservicio && in_array($cita->estado, \App\Models\Cita::ESTADOS_VIGENTES, true) && $inicio->isFuture();
                                    @endphp
                                    <tr class="align-top">
                                        <td class="whitespace-nowrap">
                                            {{-- CU20 paso 4: trazabilidad --}}
                                            <a href="{{ route('historiales-cita.mostrar', $cita->id) }}" class="font-semibold text-teal-700 hover:underline">{{ $cita->codigo }}</a>
                                            {{-- Indicadores de reprogramación (RN18) y reemplazo (CU16 A1) --}}
                                            @if ($cita->reprogramaciones > 0)
                                                <div class="text-xs text-slate-500">Reprogramada {{ $cita->reprogramaciones }} {{ $cita->reprogramaciones == 1 ? 'vez' : 'veces' }}</div>
                                            @endif
                                            @if ($cita->reemplaza_codigo)
                                                <div class="text-xs text-slate-500">Reemplaza a <a href="{{ route('historiales-cita.mostrar', $cita->cita_reemplazada_id) }}" class="hover:underline">{{ $cita->reemplaza_codigo }}</a></div>
                                            @endif
                                            @if ($cita->reemplazada_por_codigo)
                                                <div class="text-xs text-slate-500">Reemplazada por <a href="{{ route('historiales-cita.mostrar', $cita->reemplazada_por_id) }}" class="hover:underline">{{ $cita->reemplazada_por_codigo }}</a></div>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap">{{ $inicio->format('d/m/Y') }} {{ substr($cita->hora_inicio, 0, 5) }}–{{ substr($cita->hora_fin, 0, 5) }}</td>
                                        <td>{{ $cita->medico_sexo === 'F' ? 'Dra.' : 'Dr.' }} {{ $cita->medico_nombres }} {{ $cita->medico_apellido }}</td>
                                        <td>{{ $cita->especialidad }}</td>
                                        <td><x-estado-cita :estado="$cita->estado" /></td>
                                        <td>{{ $origenes[$cita->origen] ?? ($cita->origen ?? '—') }}</td>
                                        <td class="whitespace-nowrap">{{ $cita->fecha_registro?->format('d/m/Y H:i') }}</td>
                                        @if ($autoservicio)
                                            <td class="whitespace-nowrap">
                                                @if ($cambiable)
                                                    <div class="acciones">
                                                        <a href="{{ route('citas.mostrar', [$cita->id, 'accion' => 'reprogramar']) }}" class="accion">Reprogramar</a>
                                                        <a href="{{ route('citas.mostrar', [$cita->id, 'accion' => 'cancelar']) }}" class="accion-peligro">Cancelar</a>
                                                    </div>
                                                @else
                                                    <span class="block text-right text-slate-400">—</span>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $autoservicio ? 8 : 7 }}" class="py-8 text-center text-slate-500">No hay citas con esos filtros.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$citas" />
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
