{{-- RF06: detalle de la cita (con el formato del ejemplo del enunciado) y su historial (RF09).
     CU16, CU17 y CU18: solo los botones de las acciones válidas ($acciones = accionesPermitidas). --}}
@php
    $ficha = $cita->ficha;
    $horario = $ficha->horario;
    $medicoPersona = $cita->medico()->persona;
    $paciente = $cita->paciente;
    $fecha = \Illuminate\Support\Carbon::parse($ficha->fecha);
    $horas = fn ($f) => $f ? \Illuminate\Support\Carbon::parse($f->fecha)->format('d/m/Y') . ' ' . substr($f->hora_inicio, 0, 5) . '–' . substr($f->hora_fin, 0, 5) : '—';
    $seguro = $cita->seguroPaciente;
    $origen = match ($cita->origen) {
        'AUTOSERVICIO' => 'Reserva en línea',
        'RECEPCION' => 'Recepción',
        default => $cita->origen ?? '—',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Cita médica {{ $cita->codigo }}</h2>
            @canany(['citas.programar', 'agenda.consultar'])
                <a href="{{ route('citas.listar', ['fecha' => $fecha->toDateString()]) }}" class="text-sm text-indigo-600 hover:underline">Citas de ese día</a>
            @endcanany
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex items-center gap-3">
                    <h3 class="text-lg font-medium text-gray-900">Cita médica {{ $cita->codigo }}</h3>
                    <x-estado-cita :estado="$cita->estado" />
                </div>

                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                    <div><dt class="font-medium text-gray-500">Paciente</dt><dd>{{ $paciente->persona->nombre_completo }} ({{ $paciente->codigo }})</dd></div>
                    <div><dt class="font-medium text-gray-500">Especialidad</dt><dd>{{ $cita->especialidad()->nombre }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Médico</dt><dd>{{ \App\Services\CitaService::tituloMedico($medicoPersona) }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Fecha</dt><dd>{{ \App\Services\AgendaService::DIAS[$fecha->dayOfWeekIso] }} {{ $fecha->format('d/m/Y') }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Horario</dt><dd>{{ substr($ficha->hora_inicio, 0, 5) }} – {{ substr($ficha->hora_fin, 0, 5) }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Modalidad</dt><dd>{{ $horario->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</dd></div>
                    <div>
                        <dt class="font-medium text-gray-500">{{ $horario->modalidad === 'VIRTUAL' ? 'Enlace virtual' : 'Consultorio' }}</dt>
                        <dd>
                            @if ($horario->modalidad === 'VIRTUAL')
                                <a href="{{ $cita->enlace_virtual }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline break-all">{{ $cita->enlace_virtual }}</a>
                            @else
                                {{ $horario->consultorio?->nombre }} ({{ $horario->consultorio?->ubicacion }})
                            @endif
                        </dd>
                    </div>
                    <div><dt class="font-medium text-gray-500">Motivo</dt><dd>{{ $cita->motivo ?? '—' }}</dd></div>
                    <div>
                        <dt class="font-medium text-gray-500">Seguro</dt>
                        <dd>
                            {{ $seguro ? $seguro->aseguradora->nombre . ' · póliza ' . $seguro->numero_poliza : 'Sin seguro (particular)' }}
                            @if ($seguro && $cita->codigo_autorizacion_seguro) · autorización {{ $cita->codigo_autorizacion_seguro }} @endif
                        </dd>
                    </div>
                    <div><dt class="font-medium text-gray-500">Origen</dt><dd>{{ $origen }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Canal</dt><dd>{{ $cita->canal }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Registrada por</dt><dd>{{ $cita->usuarioRegistra->persona->nombre_completo }} ({{ $cita->usuarioRegistra->nombre_usuario }})</dd></div>
                    <div><dt class="font-medium text-gray-500">Fecha de registro</dt><dd>{{ $cita->fecha_registro?->format('d/m/Y H:i') }}</dd></div>
                    {{-- CU17 / RF09: el motivo de la cancelación queda para consultas históricas --}}
                    @if ($cita->estado === 'CANCELADA')
                        <div class="sm:col-span-2"><dt class="font-medium text-gray-500">Motivo de cancelación</dt><dd>{{ $cita->motivo_cancelacion }}</dd></div>
                    @endif
                    {{-- CU16 A1: enlace entre la cita original y la que la reemplaza --}}
                    @if ($cita->citaReemplazada)
                        <div><dt class="font-medium text-gray-500">Reemplaza a</dt><dd><a href="{{ route('citas.mostrar', $cita->citaReemplazada->id) }}" class="text-indigo-600 hover:underline">{{ $cita->citaReemplazada->codigo }}</a></dd></div>
                    @endif
                    @if ($cita->reemplazadaPor)
                        <div><dt class="font-medium text-gray-500">Reemplazada por</dt><dd><a href="{{ route('citas.mostrar', $cita->reemplazadaPor->id) }}" class="text-indigo-600 hover:underline">{{ $cita->reemplazadaPor->codigo }}</a></dd></div>
                    @endif
                </dl>
            </div>

            {{-- Regla de negocio que impidió el cambio (RF07–RF10, RN09–RN21) --}}
            @if ($errors->has('cita'))
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">{{ $errors->first('cita') }}</div>
            @endif
            @foreach ($avisos as $aviso)
                <div class="rounded-md bg-yellow-50 p-4 text-sm font-medium text-yellow-800">{{ $aviso }}</div>
            @endforeach

            {{-- CU16 paso 1, CU17 paso 2 y CU18 paso 2: acciones válidas en este momento --}}
            @if (count($acciones))
                {{-- Al volver con un error se reabre la sección; ?accion= la abre desde el historial (CU20 A1) --}}
                @php
                    $abrir = old('accion') && (string) old('id') === (string) $cita->id ? old('accion') : request()->query('accion');
                    $abrir = in_array($abrir, $acciones, true) && in_array($abrir, ['reprogramar', 'reasignar', 'cancelar'], true) ? $abrir : null;
                @endphp
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4" x-data="{ abierta: {{ Js::from($abrir) }} }">
                    <h3 class="text-lg font-medium text-gray-900">Acciones</h3>

                    <div class="flex flex-wrap items-center gap-2">
                        @include('citas._estado-botones', ['cita' => $cita, 'acciones' => $acciones])

                        @if (in_array('reprogramar', $acciones, true))
                            <x-secondary-button @click="abierta = abierta === 'reprogramar' ? null : 'reprogramar'">Reprogramar</x-secondary-button>
                        @endif
                        @if (in_array('reasignar', $acciones, true))
                            <x-secondary-button @click="abierta = abierta === 'reasignar' ? null : 'reasignar'">Reasignar a otro médico</x-secondary-button>
                        @endif
                        @if (in_array('cancelar', $acciones, true))
                            <x-secondary-button @click="abierta = abierta === 'cancelar' ? null : 'cancelar'" class="!text-red-700">Cancelar</x-secondary-button>
                        @endif
                    </div>

                    {{-- CU16 pasos 2 a 4: reprogramar con el mismo médico --}}
                    @if (in_array('reprogramar', $acciones, true))
                        <div class="border-t border-gray-200 pt-4 space-y-3" x-show="abierta === 'reprogramar'" x-cloak>
                            <h4 class="font-medium text-gray-900">Reprogramar la cita</h4>
                            {{-- RN18: reprogramaciones que le quedan --}}
                            <p class="text-sm text-gray-600">Le quedan {{ $restantes }} de {{ $maximo }} reprogramaciones.</p>
                            @include('citas._cambio-ficha', ['cita' => $cita, 'reasignar' => false, 'medicosReasignar' => [], 'atiende' => $atiende])
                        </div>
                    @endif

                    {{-- CU16 A1: reasignar a otro médico de la misma especialidad (no cuenta como reprogramación) --}}
                    @if (in_array('reasignar', $acciones, true))
                        <div class="border-t border-gray-200 pt-4 space-y-3" x-show="abierta === 'reasignar'" x-cloak>
                            <h4 class="font-medium text-gray-900">Reasignar a otro médico</h4>
                            @include('citas._cambio-ficha', ['cita' => $cita, 'reasignar' => true, 'medicosReasignar' => $medicosReasignar, 'atiende' => ''])
                        </div>
                    @endif

                    {{-- CU17 pasos 2 y 3: cancelar con motivo --}}
                    @if (in_array('cancelar', $acciones, true))
                        <div class="border-t border-gray-200 pt-4 space-y-3" x-show="abierta === 'cancelar'" x-cloak>
                            <h4 class="font-medium text-gray-900">Cancelar la cita</h4>
                            <p class="text-sm text-gray-600">La cita no se elimina: queda CANCELADA en el historial y el horario vuelve a estar disponible.</p>
                            @include('citas._cancelar', ['cita' => $cita])
                        </div>
                    @endif
                </div>
            @endif

            {{-- RF09: historial de la cita --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Historial</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-2">Fecha y hora</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2">Ficha</th>
                                <th class="px-3 py-2">Realizado por</th>
                                <th class="px-3 py-2">Canal</th>
                                <th class="px-3 py-2">Motivo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($cita->historiales as $historial)
                                <tr class="align-top">
                                    <td class="px-3 py-3 whitespace-nowrap">{{ $historial->fecha_hora?->format('d/m/Y H:i') }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ $historial->estado_anterior ?? '—' }} → {{ $historial->estado_nuevo }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ $historial->fichaAnterior ? $horas($historial->fichaAnterior) . ' → ' : '' }}{{ $horas($historial->fichaNueva) }}</td>
                                    <td class="px-3 py-3">{{ $historial->usuario?->nombre_usuario ?? 'SISTEMA' }}</td>
                                    <td class="px-3 py-3">{{ $historial->canal }}</td>
                                    <td class="px-3 py-3">{{ $historial->motivo ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">Sin registros en el historial</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
