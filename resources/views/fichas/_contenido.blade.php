{{-- CU14 pasos 1 a 6: filtros y fichas por médico y por día (DISPONIBLE, OCUPADA, BLOQUEADA). --}}
@php
    $claseSelect = 'mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm';
    $dias = \App\Services\AgendaService::DIAS;
    // CU14 pasos 5-6: sin sesión, el enlace lleva al login (auth); con permiso, al formulario de CU15.
    $enlaceFicha = fn ($ficha) => route('citas.crear', array_filter(['ficha_id' => $ficha->id, 'paciente_id' => auth()->check() && $paciente && Gate::allows('citas.programar') ? $paciente->id : null]));
    $clicable = auth()->guest() || $puedeReservar;
@endphp

<div class="space-y-6">
    {{-- CU14 A1: especialidades filtradas por la edad y el sexo del paciente --}}
    @if ($paciente)
        <p class="rounded-md bg-gray-50 p-3 text-sm text-gray-700">
            Especialidades que atienden a <span class="font-medium">{{ $paciente->persona->nombre_completo }}</span>
            ({{ $paciente->persona->edad }} años, {{ $paciente->persona->sexo === 'F' ? 'femenino' : 'masculino' }}).
        </p>
    @endif

    {{-- CU14 pasos 1-2: filtros (GET). novalidate: la validación la hace validarFiltrosFichas() (resources/js/citas.js). --}}
    <form id="form-fichas" method="GET" action="{{ route('fichas.disponibilidad') }}" novalidate
          x-data="{
              especialidad: {{ Js::from((string) ($filtros['especialidad_id'] ?? '')) }},
              medico: {{ Js::from((string) ($filtros['medico_id'] ?? '')) }},
              medicos: {{ Js::from($medicosPorEspecialidad) }},
              get lista() { return this.medicos[this.especialidad] ?? []; },
          }"
          onsubmit="return window.validarFiltrosFichas(this, {{ $maxDias }});"
          class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-6 items-end">
        @if ($paciente && Gate::allows('citas.programar'))
            <input type="hidden" name="paciente_id" value="{{ $paciente->id }}">
        @endif

        <div class="lg:col-span-2">
            <x-input-label for="especialidad_id" value="Especialidad" />
            {{-- Al cambiar la especialidad se recarga la lista de médicos --}}
            <select id="especialidad_id" name="especialidad_id" x-model="especialidad" @change="medico = ''" class="{{ $claseSelect }}">
                <option value="">Seleccione…</option>
                @foreach ($especialidades as $especialidad)
                    <option value="{{ $especialidad->id }}">{{ $especialidad->nombre }}</option>
                @endforeach
            </select>
            <p class="js-error mt-2 text-sm text-red-600" data-campo="especialidad_id" hidden></p>
            <x-input-error :messages="$errores->get('especialidad_id')" class="mt-2" />
        </div>

        <div class="lg:col-span-2">
            <x-input-label for="medico_id" value="Médico (opcional)" />
            <select id="medico_id" name="medico_id" x-model="medico" class="{{ $claseSelect }}">
                <option value="">Todos</option>
                <template x-for="m in lista" :key="m.id">
                    <option :value="m.id" x-text="m.nombre" :selected="String(m.id) === medico"></option>
                </template>
            </select>
        </div>

        <div>
            <x-input-label for="modalidad" value="Modalidad (opcional)" />
            <select id="modalidad" name="modalidad" class="{{ $claseSelect }}">
                <option value="">Todas</option>
                @foreach ($modalidades as $valor => $texto)
                    <option value="{{ $valor }}" @selected($filtros['modalidad'] === $valor)>{{ $texto }}</option>
                @endforeach
            </select>
            <x-input-error :messages="$errores->get('modalidad')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="consultorio_id" value="Ubicación (opcional)" />
            <select id="consultorio_id" name="consultorio_id" class="{{ $claseSelect }}">
                <option value="">Todas</option>
                @foreach ($consultorios as $consultorio)
                    <option value="{{ $consultorio->id }}" @selected((string) $filtros['consultorio_id'] === (string) $consultorio->id)>{{ $consultorio->nombre }} · {{ $consultorio->ubicacion }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <x-input-label for="desde" value="Desde" />
            <x-text-input id="desde" name="desde" type="date" min="{{ today()->toDateString() }}" class="mt-1 block w-full" :value="$filtros['desde']" />
            <p class="js-error mt-2 text-sm text-red-600" data-campo="desde" hidden></p>
            <x-input-error :messages="$errores->get('desde')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="hasta" value="Hasta (máximo {{ $maxDias }} días)" />
            <x-text-input id="hasta" name="hasta" type="date" min="{{ today()->toDateString() }}" class="mt-1 block w-full" :value="$filtros['hasta']" />
            <p class="js-error mt-2 text-sm text-red-600" data-campo="hasta" hidden></p>
            <x-input-error :messages="$errores->get('hasta')" class="mt-2" />
        </div>

        <div>
            <x-primary-button>Consultar</x-primary-button>
        </div>
    </form>

    @if ($consultado)
        {{-- Leyenda de estados --}}
        <div class="flex flex-wrap items-center gap-4 text-xs text-gray-600">
            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-green-500"></span> Disponible</span>
            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-gray-300"></span> Ocupada</span>
            <span class="inline-flex items-center gap-1"><span class="h-3 w-3 rounded bg-red-200"></span> Bloqueada</span>
            @if ($clicable)
                <span>Pulse una ficha disponible para {{ auth()->guest() ? 'iniciar sesión y reservarla' : 'programar la cita' }}.</span>
            @endif
        </div>

        {{-- CU14 excepción: sin fichas disponibles en el rango --}}
        @if ($proxima)
            <div class="rounded-md bg-yellow-50 p-4 text-sm font-medium text-yellow-800">
                No hay fichas disponibles entre las fechas elegidas. La fecha disponible más próxima es el {{ $proxima->format('d/m/Y') }}.
            </div>
        @elseif (! $hayDisponibles)
            <div class="rounded-md bg-yellow-50 p-4 text-sm font-medium text-yellow-800">
                No hay fichas disponibles entre las fechas elegidas ni en los 60 días siguientes.
            </div>
        @endif

        {{-- CU14 pasos 3-4: por médico y por día --}}
        @foreach ($medicos as $medico)
            @php $datos = $medico['datos']; @endphp
            <div class="rounded-lg border border-gray-200 p-4 space-y-3">
                <div>
                    <p class="font-semibold text-gray-900">{{ $datos->medico_sexo === 'F' ? 'Dra.' : 'Dr.' }} {{ $datos->medico_nombres }} {{ $datos->medico_apellido }} {{ $datos->medico_apellido_materno }}</p>
                    <p class="text-sm text-gray-600">{{ $datos->especialidad }} · {{ $medico['lugares']->implode(' / ') }}</p>
                </div>

                @foreach ($medico['dias'] as $fecha => $fichasDia)
                    @php $dia = \Illuminate\Support\Carbon::parse($fecha); @endphp
                    <div>
                        <p class="text-sm font-medium text-gray-700">{{ $dias[$dia->dayOfWeekIso] }} {{ $dia->format('d/m/Y') }}</p>
                        <div class="mt-1 flex flex-wrap gap-2">
                            @foreach ($fichasDia as $ficha)
                                @php $horas = substr($ficha->hora_inicio, 0, 5) . '–' . substr($ficha->hora_fin, 0, 5); @endphp
                                @if ($ficha->estado_visible === 'DISPONIBLE' && $clicable)
                                    <a href="{{ $enlaceFicha($ficha) }}" class="rounded-md bg-green-500 px-2 py-1 text-xs font-semibold text-white hover:bg-green-600" title="Disponible">{{ $horas }}</a>
                                @elseif ($ficha->estado_visible === 'DISPONIBLE')
                                    <span class="rounded-md bg-green-500 px-2 py-1 text-xs font-semibold text-white" title="Disponible">{{ $horas }}</span>
                                @elseif ($ficha->estado_visible === 'OCUPADA')
                                    <span class="rounded-md bg-gray-300 px-2 py-1 text-xs font-semibold text-gray-700" title="Ocupada">{{ $horas }}</span>
                                @else
                                    <span class="rounded-md bg-red-200 px-2 py-1 text-xs font-semibold text-red-900" title="{{ $ficha->motivo_bloqueo ?? 'Bloqueada' }}">{{ $horas }}</span>
                                @endif
                            @endforeach
                        </div>
                        {{-- Motivo del bloqueo del día (feriado, mantenimiento, vacaciones) --}}
                        @php $motivos = $fichasDia->where('estado_visible', 'BLOQUEADA')->pluck('motivo_bloqueo')->filter()->unique(); @endphp
                        @if ($motivos->isNotEmpty())
                            <p class="mt-1 text-xs text-red-700">Bloqueada: {{ $motivos->implode('; ') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif
</div>
