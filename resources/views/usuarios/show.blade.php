{{-- CU04: ficha de la cuenta de acceso, la persona, sus perfiles y sus últimas acciones. --}}
@php
    $persona = $usuario->persona;
    $bloqueado = $usuario->bloqueado_hasta?->isFuture();
    $documento = $persona->numero_documento
        ? trim((\App\Models\Persona::TIPOS_DOCUMENTO[$persona->tipo_documento] ?? $persona->tipo_documento) . ' ' . $persona->numero_documento
            . ($persona->complemento ? '-' . $persona->complemento : '') . ($persona->expedido ? ' ' . $persona->expedido : ''))
        : 'Sin documento';
    $perfiles = [
        'Médico' => [$persona->medico, $persona->medico ? route('medicos.mostrar', $persona->medico->id) : null, 'medicos.gestionar'],
        'Paciente' => [$persona->paciente, $persona->paciente ? route('pacientes.mostrar', $persona->paciente->id) : null, 'pacientes.buscar'],
        'Recepcionista' => [$persona->recepcionista, null, null],
        'Administrador' => [$persona->administrador, null, null],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Usuario: {{ $usuario->nombre_usuario }}
            </h2>
            <a href="{{ route('usuarios.listar') }}" class="text-sm text-indigo-600 hover:underline">Volver a la lista</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU04 paso 8 / A2: contraseña temporal, UNA sola vez (no se guarda en ningún lado visible) --}}
            @if ($temporal)
                <div class="rounded-md border-2 border-indigo-300 bg-indigo-50 p-5">
                    <p class="text-sm font-medium text-indigo-900">Contraseña temporal de «{{ $usuario->nombre_usuario }}»:</p>
                    <p class="mt-2 select-all font-mono text-2xl font-bold tracking-wider text-indigo-900">{{ $temporal }}</p>
                    <p class="mt-2 text-sm text-indigo-900">Entréguela al usuario: deberá cambiarla al ingresar. No se volverá a mostrar.</p>
                    <p class="mt-1 text-xs text-indigo-700">También se envió al correo de acceso {{ $usuario->correo }}.</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Datos de la cuenta --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Cuenta de acceso</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="font-medium text-gray-500">Nombre de usuario</dt><dd class="mt-1">{{ $usuario->nombre_usuario }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Correo de acceso</dt><dd class="mt-1">{{ $usuario->correo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Rol</dt><dd class="mt-1">{{ $usuario->rol->nombre }} @if ($usuario->rol->estado !== 'ACTIVO') <span class="text-xs text-yellow-700">(rol inactivo)</span> @endif</dd></div>
                    <div><dt class="font-medium text-gray-500">Estado</dt><dd class="mt-1"><x-estado :estado="$usuario->estado" /></dd></div>
                    <div><dt class="font-medium text-gray-500">Último acceso</dt><dd class="mt-1">{{ $usuario->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca' }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Intentos fallidos</dt><dd class="mt-1">{{ $usuario->intentos_fallidos }}</dd></div>
                    <div>
                        <dt class="font-medium text-gray-500">Bloqueo</dt>
                        <dd class="mt-1">{{ $bloqueado ? 'Bloqueado hasta ' . $usuario->bloqueado_hasta->format('d/m/Y H:i') : 'No bloqueado' }}</dd>
                    </div>
                    <div><dt class="font-medium text-gray-500">Debe cambiar la contraseña</dt><dd class="mt-1">{{ $usuario->debe_cambiar_password ? 'Sí' : 'No' }}</dd></div>
                </dl>

                {{-- CU04 A2 y A3 --}}
                <div class="flex flex-wrap items-center gap-2 border-t border-gray-100 pt-4">
                    @if ($bloqueado)
                        <form method="POST" action="{{ route('usuarios.desbloquear') }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $usuario->id }}">
                            <x-secondary-button type="submit">Desbloquear</x-secondary-button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('usuarios.restablecer') }}"
                          onsubmit="return confirm({{ Js::from('¿Restablecer la contraseña de «'.$usuario->nombre_usuario.'»? Se generará una contraseña temporal nueva y se cerrarán sus sesiones abiertas.') }});">
                        @csrf
                        <input type="hidden" name="id" value="{{ $usuario->id }}">
                        <x-secondary-button type="submit">Restablecer contraseña</x-secondary-button>
                    </form>

                    @if ($usuario->estado === 'ACTIVO' && ! $esPropio)
                        <form method="POST" action="{{ route('usuarios.eliminar') }}"
                              onsubmit="return confirm({{ Js::from('¿Desactivar la cuenta «'.$usuario->nombre_usuario.'»?') }});">
                            @csrf
                            <input type="hidden" name="id" value="{{ $usuario->id }}">
                            <x-danger-button>Desactivar</x-danger-button>
                        </form>
                    @elseif ($usuario->estado !== 'ACTIVO')
                        <form method="POST" action="{{ route('usuarios.activar') }}">
                            @csrf
                            <input type="hidden" name="id" value="{{ $usuario->id }}">
                            <x-primary-button>Activar</x-primary-button>
                        </form>
                    @endif

                    <a href="{{ route('usuarios.listar', ['q' => $usuario->nombre_usuario]) }}" class="text-sm text-indigo-600 hover:underline">Modificar (en la lista)</a>
                </div>
            </div>

            {{-- Persona y perfiles --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Persona</h3>
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="font-medium text-gray-500">Nombre</dt><dd class="mt-1">{{ $persona->nombre_completo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Documento</dt><dd class="mt-1">{{ $documento }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Fecha de nacimiento</dt><dd class="mt-1">{{ $persona->fecha_nacimiento?->format('d/m/Y') }} ({{ $persona->edad }} años)</dd></div>
                    <div><dt class="font-medium text-gray-500">Teléfono</dt><dd class="mt-1">{{ $persona->telefono }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Correo de contacto</dt><dd class="mt-1">{{ $persona->correo ?? '—' }}</dd></div>
                </dl>

                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead>
                        <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <th class="px-3 py-2">Perfil</th>
                            <th class="px-3 py-2">Código</th>
                            <th class="px-3 py-2">Estado</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($perfiles as $nombre => [$modelo, $enlace, $permiso])
                            <tr>
                                <td class="px-3 py-2">{{ $nombre }}</td>
                                <td class="px-3 py-2">
                                    @if (! $modelo)
                                        <span class="text-gray-400">No tiene</span>
                                    @elseif ($enlace && auth()->user()->can($permiso))
                                        <a href="{{ $enlace }}" class="text-indigo-600 hover:underline">{{ $modelo->codigo }}</a>
                                    @else
                                        {{ $modelo->codigo }}
                                    @endif
                                </td>
                                <td class="px-3 py-2">@if ($modelo) <x-estado :estado="$modelo->estado" /> @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Últimas 20 acciones en la bitácora --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                <h3 class="text-lg font-medium text-gray-900">Últimas acciones</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                <th class="px-3 py-2">Fecha</th>
                                <th class="px-3 py-2">Acción</th>
                                <th class="px-3 py-2">Módulo</th>
                                <th class="px-3 py-2">Resultado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($acciones as $accion)
                                <tr>
                                    <td class="px-3 py-2 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($accion->fecha_hora)->format('d/m/Y H:i:s') }}</td>
                                    <td class="px-3 py-2">{{ $accion->accion }}</td>
                                    <td class="px-3 py-2">{{ $accion->modulo }}</td>
                                    <td class="px-3 py-2">
                                        <span @class(['font-semibold', 'text-green-700' => $accion->exito, 'text-red-700' => ! $accion->exito])>{{ $accion->exito ? 'Éxito' : 'Fallido' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-3 py-6 text-center text-gray-500">Este usuario aún no tiene acciones registradas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
