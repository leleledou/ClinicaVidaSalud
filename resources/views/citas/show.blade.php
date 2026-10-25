{{-- RF06: detalle de la cita (con el formato del ejemplo del enunciado) y su historial (RF09). --}}
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
                </dl>
            </div>

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
