{{-- CU11 paso 5 / CU10 A4: ficha del paciente y sus citas (RF02: consultar sus citas anteriores). --}}
@php
    $persona = $paciente->persona;
    $documento = $persona->numero_documento
        ? trim((\App\Models\Persona::TIPOS_DOCUMENTO[$persona->tipo_documento] ?? $persona->tipo_documento) . ' ' . $persona->numero_documento
            . ($persona->complemento ? '-' . $persona->complemento : '') . ($persona->expedido ? ' ' . $persona->expedido : ''))
        : 'Sin documento';
    // Quién lo registró: la recepcionista o, si no hay, autorregistro por la web.
    $registradoPor = $paciente->recepcionista
        ? $paciente->recepcionista->persona->nombre_completo . ' (' . $paciente->recepcionista->codigo . ')'
        : 'Autorregistro';
    // Colores por estado de la cita.
    $colorCita = fn ($estado) => match ($estado) {
        'PROGRAMADA' => 'bg-blue-100 text-blue-800',
        'CONFIRMADA' => 'bg-indigo-100 text-indigo-800',
        'ATENDIDA' => 'bg-green-100 text-green-800',
        'CANCELADA' => 'bg-gray-200 text-gray-700',
        default => 'bg-red-100 text-red-800',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $paciente->codigo }} · {{ $persona->nombre_completo }}
            </h2>
            <div class="flex items-center gap-4">
                {{-- CU13: vínculos del paciente (recepción con vinculos.gestionar o vinculos.verificar) --}}
                @canany(['vinculos.gestionar', 'vinculos.verificar'])
                    <a href="{{ route('vinculos-paciente.listar', ['paciente_id' => $paciente->id]) }}"
                       class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">
                        Vínculos
                    </a>
                @endcanany
                <a href="{{ route('pacientes.listar') }}" class="text-sm text-indigo-600 hover:underline">Volver a la lista</a>
            </div>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Ficha del paciente --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="font-medium text-gray-500">Código</dt><dd class="mt-1">{{ $paciente->codigo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Estado</dt><dd class="mt-1"><x-estado :estado="$paciente->estado" /></dd></div>
                    <div><dt class="font-medium text-gray-500">Documento</dt><dd class="mt-1">{{ $documento }}</dd></div>
                    <div>
                        <dt class="font-medium text-gray-500">Fecha de nacimiento</dt>
                        <dd class="mt-1">
                            {{ $persona->fecha_nacimiento?->format('d/m/Y') }} ({{ $persona->edad }} años)
                            @if ($persona->edad < $edadMayoria)
                                <span class="ms-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-800">Menor de edad</span>
                            @endif
                        </dd>
                    </div>
                    <div><dt class="font-medium text-gray-500">Sexo</dt><dd class="mt-1">{{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Teléfono</dt><dd class="mt-1">{{ $persona->telefono }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Correo</dt><dd class="mt-1">{{ $persona->correo ?? '—' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="font-medium text-gray-500">Dirección</dt><dd class="mt-1">{{ $persona->direccion ?? '—' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Registrado por</dt><dd class="mt-1">{{ $registradoPor }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Fecha de registro</dt><dd class="mt-1">{{ $paciente->fecha_registro?->format('d/m/Y H:i') }}</dd></div>
                    <div>
                        <dt class="font-medium text-gray-500">Historia clínica</dt>
                        <dd class="mt-1">
                            {{ $paciente->historiaClinica ? 'Abierta el ' . \Illuminate\Support\Carbon::parse($paciente->historiaClinica->fecha_apertura)->format('d/m/Y') : 'Sin historia clínica' }}
                        </dd>
                    </div>
                </dl>

                {{-- CU10 A3: desactivar o activar (solo con pacientes.gestionar); se modifica desde la lista --}}
                @can('pacientes.gestionar')
                    <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                        @if ($paciente->estado === 'ACTIVO')
                            <form method="POST" action="{{ route('pacientes.eliminar') }}"
                                  onsubmit="return confirm({{ Js::from('¿Desactivar al paciente '.$persona->nombre_completo.' ('.$paciente->codigo.')?') }});">
                                @csrf
                                <input type="hidden" name="id" value="{{ $paciente->id }}">
                                <x-danger-button>Desactivar</x-danger-button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('pacientes.activar') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $paciente->id }}">
                                <x-primary-button>Activar</x-primary-button>
                            </form>
                        @endif
                        <a href="{{ route('pacientes.listar', ['q' => $paciente->codigo]) }}" class="text-sm text-indigo-600 hover:underline">Modificar datos (en la lista)</a>
                    </div>
                @endcan
            </div>

            {{-- CU13: resumen de los vínculos activos (contactos de emergencia y responsables legales) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-medium text-gray-900">Vínculos activos</h3>
                    @canany(['vinculos.gestionar', 'vinculos.verificar'])
                        <a href="{{ route('vinculos-paciente.listar', ['paciente_id' => $paciente->id]) }}" class="text-sm text-indigo-600 hover:underline">Gestionar vínculos</a>
                    @endcanany
                </div>
                @if ($persona->edad < $edadMayoria && ! $vinculos->contains(fn ($v) => $v->es_responsable_legal && $v->fecha_verificacion))
                    <div class="rounded-md bg-red-50 p-3 text-sm font-medium text-red-800">
                        Este paciente es menor de edad y no tiene un responsable legal verificado: no podrá recibir citas (RN16).
                    </div>
                @endif
                <ul class="divide-y divide-gray-100 text-sm">
                    @forelse ($vinculos as $vinculo)
                        <li class="py-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span class="font-medium">{{ $vinculo->persona->nombre_completo }}</span>
                            <span class="text-gray-600">{{ $vinculo->parentesco }} · {{ $vinculo->tipoTexto() }}</span>
                            <span class="text-gray-600">Tel. {{ $vinculo->persona->telefono }}</span>
                            @if ($vinculo->fecha_verificacion)
                                <span class="text-green-700">Verificado el {{ $vinculo->fecha_verificacion->format('d/m/Y') }}</span>
                            @else
                                <span class="text-yellow-700">Pendiente de verificación</span>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-gray-500">Sin vínculos activos</li>
                    @endforelse
                </ul>
            </div>

            {{-- CU10 A4: citas del paciente, de la más reciente a la más antigua (incluidas las canceladas) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Citas</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-2">Código</th>
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Hora</th>
                                <th class="px-3 py-2">Médico</th>
                                <th class="px-3 py-2">Especialidad</th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2">Registrada el</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($citas as $cita)
                                <tr>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ $cita->codigo }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($cita->fecha)->format('d/m/Y') }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ substr($cita->hora_inicio, 0, 5) }}</td>
                                    <td class="px-3 py-3">{{ $cita->medico_nombres }} {{ $cita->medico_apellido }}</td>
                                    <td class="px-3 py-3">{{ $cita->especialidad }}</td>
                                    <td class="px-3 py-3">
                                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $colorCita($cita->estado) }}">{{ $cita->estado }}</span>
                                    </td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($cita->fecha_registro)->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">El paciente aún no tiene citas registradas</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
