{{-- CU09: ficha del horario y sus fichas desde hoy, agrupadas por fecha. --}}
@php
    $asignacion = $horario->medicoEspecialidad;
    $hora = fn ($valor) => substr($valor, 0, 5);
    $fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y') : null;
    $porFecha = $fichas->getCollection()->groupBy('fecha');
    $hoy = today()->toDateString();
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Horario: {{ $dias[$horario->dia_semana] }} {{ $hora($horario->hora_inicio) }}–{{ $hora($horario->hora_fin) }}
            </h2>
            <a href="{{ route('horarios.listar', ['medico_id' => $asignacion->medico_id]) }}" class="text-sm text-indigo-600 hover:underline">Volver a los horarios del médico</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Datos del horario --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <dl class="grid grid-cols-1 sm:grid-cols-4 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="font-medium text-gray-500">Médico</dt><dd class="mt-1">{{ $asignacion->medico->codigo }} · {{ $asignacion->medico->persona->nombre_completo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Especialidad</dt><dd class="mt-1">{{ $asignacion->especialidad->nombre }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Día y horario</dt><dd class="mt-1">{{ $dias[$horario->dia_semana] }} de {{ $hora($horario->hora_inicio) }} a {{ $hora($horario->hora_fin) }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Duración de la ficha</dt><dd class="mt-1">{{ $horario->duracion_ficha_min }} minutos</dd></div>
                    <div><dt class="font-medium text-gray-500">Modalidad</dt><dd class="mt-1">{{ $horario->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Consultorio</dt><dd class="mt-1">{{ $horario->consultorio?->nombre ?? '—' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Vigencia</dt><dd class="mt-1">Desde {{ $fecha($horario->vigente_desde) }} {{ $horario->vigente_hasta ? 'hasta ' . $fecha($horario->vigente_hasta) : '(sin fecha de fin)' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Estado</dt><dd class="mt-1"><x-estado :estado="$vigente ? 'ACTIVO' : 'INACTIVO'" /> {{ $vigente ? '' : '(cerrado)' }}</dd></div>
                </dl>

                @if ($vigente)
                    <div class="flex flex-wrap items-end gap-4 border-t border-gray-100 pt-4">
                        {{-- CU09 A2 --}}
                        <form method="POST" action="{{ route('horarios.generar') }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $horario->id }}">
                            <x-secondary-button type="submit">Generar más semanas</x-secondary-button>
                        </form>

                        {{-- CU09 A1: cerrar la vigencia --}}
                        <form method="POST" action="{{ route('horarios.eliminar') }}" class="flex items-end gap-2"
                              onsubmit="return confirm('¿Cerrar este horario? Se eliminarán sus fichas libres posteriores a la fecha de cierre.');">
                            @csrf
                            <input type="hidden" name="id" value="{{ $horario->id }}">
                            <div>
                                <x-input-label for="fecha_cierre" value="Fecha de cierre" />
                                <x-text-input id="fecha_cierre" name="fecha_cierre" type="date" :value="$hoy" min="{{ $hoy }}" class="mt-1 block" required />
                            </div>
                            <x-danger-button>Cerrar vigencia</x-danger-button>
                        </form>
                    </div>
                @endif
            </div>

            {{-- Fichas desde hoy --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Fichas desde hoy</h3>

                @forelse ($porFecha as $dia => $lista)
                    <div>
                        <p class="mb-2 text-sm font-semibold text-gray-700">{{ $dias[\Illuminate\Support\Carbon::parse($dia)->dayOfWeekIso] }} {{ $fecha($dia) }}</p>
                        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2">
                            @foreach ($lista as $ficha)
                                @php
                                    $estado = $ficha->cita_codigo ? 'OCUPADA' : ($ficha->estado === 'BLOQUEADA' ? 'BLOQUEADA' : 'DISPONIBLE');
                                @endphp
                                <div @class([
                                    'rounded-md border p-2 text-xs',
                                    'border-green-200 bg-green-50 text-green-800' => $estado === 'DISPONIBLE',
                                    'border-blue-200 bg-blue-50 text-blue-800' => $estado === 'OCUPADA',
                                    'border-gray-200 bg-gray-100 text-gray-600' => $estado === 'BLOQUEADA',
                                ])>
                                    <p class="font-semibold">{{ $hora($ficha->hora_inicio) }}–{{ $hora($ficha->hora_fin) }}</p>
                                    <p>{{ $estado }}</p>
                                    @if ($estado === 'OCUPADA')
                                        <p>Cita {{ $ficha->cita_codigo }}</p>
                                    @elseif ($estado === 'BLOQUEADA' && ($motivos[$ficha->id] ?? null))
                                        <p>{{ $motivos[$ficha->id] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-gray-500">El horario no tiene fichas desde hoy.@if ($vigente) Use «Generar más semanas».@endif</p>
                @endforelse

                <x-paginacion :paginador="$fichas" />
            </div>
        </div>
    </div>
</x-app-layout>
