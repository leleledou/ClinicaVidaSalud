{{-- CU07: ficha del médico con sus datos, especialidades y horarios. --}}
@php
    $persona = $medico->persona;
    $dias = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];
    $fecha = fn ($valor) => \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y');
    $documento = $persona->numero_documento
        ? trim((\App\Models\Persona::TIPOS_DOCUMENTO[$persona->tipo_documento] ?? $persona->tipo_documento) . ' ' . $persona->numero_documento
            . ($persona->complemento ? '-' . $persona->complemento : '') . ($persona->expedido ? ' ' . $persona->expedido : ''))
        : '—';
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Catálogos / Médicos" :titulo="$medico->codigo . ' · ' . $persona->nombre_completo">
            <a href="{{ route('medicos.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            {{-- Datos personales y profesionales --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Datos del médico</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Código</dt><dd>{{ $medico->codigo }}</dd></div>
                    <div class="dato"><dt>Estado</dt><dd><x-estado :estado="$medico->estado" /></dd></div>
                    <div class="dato"><dt>Registro profesional</dt><dd>{{ $medico->registro_profesional }}</dd></div>
                    <div class="dato"><dt>Documento</dt><dd>{{ $documento }}</dd></div>
                    <div class="dato"><dt>Fecha de nacimiento</dt><dd>{{ $persona->fecha_nacimiento?->format('d/m/Y') }} ({{ $persona->edad }} años)</dd></div>
                    <div class="dato"><dt>Sexo</dt><dd>{{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                    <div class="dato"><dt>Teléfono</dt><dd>{{ $persona->telefono }}</dd></div>
                    <div class="dato"><dt>Correo</dt><dd>{{ $persona->correo ?? '—' }}</dd></div>
                    <div class="dato"><dt>Dirección</dt><dd>{{ $persona->direccion ?? '—' }}</dd></div>
                    <div class="dato xl:col-span-2"><dt>Biografía</dt><dd>{{ $medico->biografia ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Especialidades (principal y estado) --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Especialidades</h2>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Especialidad</th>
                                <th>Principal</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($medico->especialidades as $especialidad)
                                <tr>
                                    <td>{{ $especialidad->nombre }}</td>
                                    <td>{{ $especialidad->pivot->es_principal ? 'Sí' : 'No' }}</td>
                                    <td><x-estado :estado="$especialidad->pivot->estado" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-8 text-center text-slate-500">El médico no tiene especialidades registradas.</td></tr>
                            @endforelse
                        </tbody>
    </table>
                </div>
            </div>

            {{-- Horarios de atención --}}
            <div class="tarjeta space-y-4">
                <div class="flex items-center justify-between">
                    <h2 class="tarjeta-titulo">Horarios de atención</h2>
                    {{-- CU09: gestionar los horarios de este médico --}}
                    @can('horarios.gestionar')
                        <a href="{{ route('horarios.listar', ['medico_id' => $medico->id]) }}" class="text-sm font-semibold text-marino-900 hover:text-teal-700 hover:underline">Gestionar horarios</a>
                    @endcan
                </div>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Especialidad</th>
                                <th>Día</th>
                                <th>Horario</th>
                                <th>Modalidad</th>
                                <th>Consultorio</th>
                                <th>Vigencia</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($horarios as $horario)
                                <tr>
                                    <td>{{ $horario->especialidad }}</td>
                                    <td>{{ $dias[$horario->dia_semana] ?? $horario->dia_semana }}</td>
                                    <td class="whitespace-nowrap">{{ substr($horario->hora_inicio, 0, 5) }} – {{ substr($horario->hora_fin, 0, 5) }}</td>
                                    <td>{{ $horario->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</td>
                                    <td>{{ $horario->consultorio ?? '—' }}</td>
                                    <td class="whitespace-nowrap">
                                        Desde {{ $fecha($horario->vigente_desde) }}
                                        {{ $horario->vigente_hasta ? 'hasta ' . $fecha($horario->vigente_hasta) : '(sin fecha de fin)' }}
                                    </td>
                                    <td><x-estado :estado="$horario->estado" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="py-8 text-center text-slate-500">El médico no tiene horarios registrados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
