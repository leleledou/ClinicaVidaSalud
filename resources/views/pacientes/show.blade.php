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
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Pacientes / Ficha" :titulo="$paciente->codigo . ' · ' . $persona->nombre_completo">
            {{-- CU15 paso 1: programar una cita con este paciente ya elegido --}}
            @can('citas.programar')
                @if ($paciente->estado === 'ACTIVO')
                    <a href="{{ route('citas.crear', ['paciente_id' => $paciente->id]) }}"
                       class="btn-primario btn-chico">
                        Programar cita
                    </a>
                @endif
            @endcan
            {{-- CU13: vínculos del paciente (recepción con vinculos.gestionar o vinculos.verificar) --}}
            @canany(['vinculos.gestionar', 'vinculos.verificar'])
                <a href="{{ route('vinculos-paciente.listar', ['paciente_id' => $paciente->id]) }}"
                   class="btn-secundario btn-chico">
                    Vínculos
                </a>
            @endcanany
            {{-- CU20 paso 1: historial completo de citas del paciente --}}
            @can('citas.historial_consultar')
                <a href="{{ route('historiales-cita.listar', ['paciente_id' => $paciente->id]) }}"
                   class="btn-secundario btn-chico">
                    Historial de citas
                </a>
            @endcan
            <a href="{{ route('pacientes.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            {{-- Ficha del paciente --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Datos del paciente</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Código</dt><dd>{{ $paciente->codigo }}</dd></div>
                    <div class="dato"><dt>Estado</dt><dd><x-estado :estado="$paciente->estado" /></dd></div>
                    <div class="dato"><dt>Documento</dt><dd>{{ $documento }}</dd></div>
                    <div class="dato">
                        <dt>Fecha de nacimiento</dt>
                        <dd>
                            {{ $persona->fecha_nacimiento?->format('d/m/Y') }} ({{ $persona->edad }} años)
                            @if ($persona->edad < $edadMayoria)
                                <span class="ms-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Menor de edad</span>
                            @endif
                        </dd>
                    </div>
                    <div class="dato"><dt>Sexo</dt><dd>{{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                    <div class="dato"><dt>Teléfono</dt><dd>{{ $persona->telefono }}</dd></div>
                    <div class="dato"><dt>Correo</dt><dd>{{ $persona->correo ?? '—' }}</dd></div>
                    <div class="dato xl:col-span-2"><dt>Dirección</dt><dd>{{ $persona->direccion ?? '—' }}</dd></div>
                    <div class="dato"><dt>Registrado por</dt><dd>{{ $registradoPor }}</dd></div>
                    <div class="dato"><dt>Fecha de registro</dt><dd>{{ $paciente->fecha_registro?->format('d/m/Y H:i') }}</dd></div>
                    <div class="dato">
                        <dt>Historia clínica</dt>
                        <dd>
                            {{ $paciente->historiaClinica ? 'Abierta el ' . \Illuminate\Support\Carbon::parse($paciente->historiaClinica->fecha_apertura)->format('d/m/Y') : 'Sin historia clínica' }}
                        </dd>
                    </div>
                </dl>

                {{-- CU10 A3: desactivar o activar (solo con pacientes.gestionar); se modifica desde la lista --}}
                @can('pacientes.gestionar')
                    <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
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
                        <a href="{{ route('pacientes.listar', ['q' => $paciente->codigo]) }}" class="text-sm font-semibold text-teal-700 hover:underline">Modificar datos (en la lista)</a>
                    </div>
                @endcan
            </div>

            {{-- CU13: resumen de los vínculos activos (contactos de emergencia y responsables legales) --}}
            <div class="tarjeta space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="tarjeta-titulo">Vínculos activos</h2>
                    @canany(['vinculos.gestionar', 'vinculos.verificar'])
                        <a href="{{ route('vinculos-paciente.listar', ['paciente_id' => $paciente->id]) }}" class="text-sm font-semibold text-teal-700 hover:underline">Gestionar vínculos</a>
                    @endcanany
                </div>
                @if ($persona->edad < $edadMayoria && ! $vinculos->contains(fn ($v) => $v->es_responsable_legal && $v->fecha_verificacion))
                    <div class="alerta-error font-medium">
                        Este paciente es menor de edad y no tiene un responsable legal verificado: no podrá recibir citas (RN16).
                    </div>
                @endif
                <ul class="divide-y divide-slate-100 text-sm">
                    @forelse ($vinculos as $vinculo)
                        <li class="py-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span class="font-medium">{{ $vinculo->persona->nombre_completo }}</span>
                            <span class="text-slate-600">{{ $vinculo->parentesco }} · {{ $vinculo->tipoTexto() }}</span>
                            <span class="text-slate-600">Tel. {{ $vinculo->persona->telefono }}</span>
                            @if ($vinculo->fecha_verificacion)
                                <span class="text-green-700">Verificado el {{ $vinculo->fecha_verificacion->format('d/m/Y') }}</span>
                            @else
                                <span class="text-amber-700">Pendiente de verificación</span>
                            @endif
                        </li>
                    @empty
                        <li class="py-2 text-slate-500">Sin vínculos activos</li>
                    @endforelse
                </ul>
            </div>

            {{-- CU10 A4 / CU20 paso 1: las 5 citas más recientes (incluidas las canceladas) y el enlace al historial completo --}}
            <div class="tarjeta space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="tarjeta-titulo">Citas recientes</h2>
                    @can('citas.historial_consultar')
                        @if ($totalCitas > 0)
                            <a href="{{ route('historiales-cita.listar', ['paciente_id' => $paciente->id]) }}" class="text-sm font-semibold text-teal-700 hover:underline">
                                Ver el historial completo ({{ $totalCitas }} {{ $totalCitas === 1 ? 'cita' : 'citas' }})
                            </a>
                        @endif
                    @endcan
                </div>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Fecha</th>
                                <th>Hora</th>
                                <th>Médico</th>
                                <th>Especialidad</th>
                                <th>Estado</th>
                                <th>Registrada el</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($citas as $cita)
                                <tr>
                                    <td class="whitespace-nowrap">
                                        {{-- CU20 paso 4: trazabilidad de la cita --}}
                                        @can('citas.historial_consultar')
                                            <a href="{{ route('historiales-cita.mostrar', $cita->id) }}" class="text-teal-700 hover:underline">{{ $cita->codigo }}</a>
                                        @else
                                            {{ $cita->codigo }}
                                        @endcan
                                    </td>
                                    <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($cita->fecha)->format('d/m/Y') }}</td>
                                    <td class="whitespace-nowrap">{{ substr($cita->hora_inicio, 0, 5) }}</td>
                                    <td>{{ $cita->medico_nombres }} {{ $cita->medico_apellido }}</td>
                                    <td>{{ $cita->especialidad }}</td>
                                    <td><x-estado-cita :estado="$cita->estado" /></td>
                                    <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($cita->fecha_registro)->format('d/m/Y H:i') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-8 text-center text-slate-500">El paciente aún no tiene citas registradas</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
