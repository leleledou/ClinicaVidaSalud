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
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $medico->codigo }} · {{ $persona->nombre_completo }}
            </h2>
            <a href="{{ route('medicos.listar') }}" class="text-sm text-indigo-600 hover:underline">Volver a la lista</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Datos personales y profesionales --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="font-medium text-gray-500">Código</dt><dd class="mt-1">{{ $medico->codigo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Estado</dt><dd class="mt-1"><x-estado :estado="$medico->estado" /></dd></div>
                    <div><dt class="font-medium text-gray-500">Registro profesional</dt><dd class="mt-1">{{ $medico->registro_profesional }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Documento</dt><dd class="mt-1">{{ $documento }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Fecha de nacimiento</dt><dd class="mt-1">{{ $persona->fecha_nacimiento?->format('d/m/Y') }} ({{ $persona->edad }} años)</dd></div>
                    <div><dt class="font-medium text-gray-500">Sexo</dt><dd class="mt-1">{{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Teléfono</dt><dd class="mt-1">{{ $persona->telefono }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Correo</dt><dd class="mt-1">{{ $persona->correo ?? '—' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Dirección</dt><dd class="mt-1">{{ $persona->direccion ?? '—' }}</dd></div>
                    <div class="sm:col-span-3"><dt class="font-medium text-gray-500">Biografía</dt><dd class="mt-1">{{ $medico->biografia ?? '—' }}</dd></div>
                </dl>
            </div>

            {{-- Especialidades (principal y estado) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Especialidades</h3>
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <th class="px-3 py-2">Especialidad</th>
                            <th class="px-3 py-2">Principal</th>
                            <th class="px-3 py-2">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($medico->especialidades as $especialidad)
                            <tr>
                                <td class="px-3 py-3">{{ $especialidad->nombre }}</td>
                                <td class="px-3 py-3">{{ $especialidad->pivot->es_principal ? 'Sí' : 'No' }}</td>
                                <td class="px-3 py-3"><x-estado :estado="$especialidad->pivot->estado" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-6 text-center text-gray-500">El médico no tiene especialidades registradas.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Horarios de atención --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Horarios de atención</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-2">Especialidad</th>
                                <th class="px-3 py-2">Día</th>
                                <th class="px-3 py-2">Horario</th>
                                <th class="px-3 py-2">Modalidad</th>
                                <th class="px-3 py-2">Consultorio</th>
                                <th class="px-3 py-2">Vigencia</th>
                                <th class="px-3 py-2">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($horarios as $horario)
                                <tr>
                                    <td class="px-3 py-3">{{ $horario->especialidad }}</td>
                                    <td class="px-3 py-3">{{ $dias[$horario->dia_semana] ?? $horario->dia_semana }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">{{ substr($horario->hora_inicio, 0, 5) }} – {{ substr($horario->hora_fin, 0, 5) }}</td>
                                    <td class="px-3 py-3">{{ $horario->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</td>
                                    <td class="px-3 py-3">{{ $horario->consultorio ?? '—' }}</td>
                                    <td class="px-3 py-3 whitespace-nowrap">
                                        Desde {{ $fecha($horario->vigente_desde) }}
                                        {{ $horario->vigente_hasta ? 'hasta ' . $fecha($horario->vigente_hasta) : '(sin fecha de fin)' }}
                                    </td>
                                    <td class="px-3 py-3"><x-estado :estado="$horario->estado" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">El médico no tiene horarios registrados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
