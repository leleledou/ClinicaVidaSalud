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
        <x-encabezado ruta="Seguridad / Usuarios" :titulo="'Usuario: ' . $usuario->nombre_usuario">
            <a href="{{ route('usuarios.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            {{-- CU04 paso 8 / A2: contraseña temporal, UNA sola vez (no se guarda en ningún lado visible) --}}
            @if ($temporal)
                <div class="rounded-2xl border-2 border-teal-300 bg-teal-50 p-6">
                    <p class="text-sm font-medium text-teal-900">Contraseña temporal de «{{ $usuario->nombre_usuario }}»:</p>
                    <p class="mt-2 select-all font-mono text-2xl font-bold tracking-wider text-teal-900">{{ $temporal }}</p>
                    <p class="mt-2 text-sm text-teal-900">Entréguela al usuario: deberá cambiarla al ingresar. No se volverá a mostrar.</p>
                    <p class="mt-1 text-xs text-teal-700">También se envió al correo de acceso {{ $usuario->correo }}.</p>
                </div>
            @endif

            @if ($errors->any())
                <div class="alerta-error font-medium">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Datos de la cuenta --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Cuenta de acceso</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Nombre de usuario</dt><dd>{{ $usuario->nombre_usuario }}</dd></div>
                    <div class="dato"><dt>Correo de acceso</dt><dd>{{ $usuario->correo }}</dd></div>
                    <div class="dato"><dt>Rol</dt><dd>{{ $usuario->rol->nombre }} @if ($usuario->rol->estado !== 'ACTIVO') <span class="text-xs text-amber-700">(rol inactivo)</span> @endif</dd></div>
                    <div class="dato"><dt>Estado</dt><dd><x-estado :estado="$usuario->estado" /></dd></div>
                    <div class="dato"><dt>Último acceso</dt><dd>{{ $usuario->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca' }}</dd></div>
                    <div class="dato"><dt>Intentos fallidos</dt><dd>{{ $usuario->intentos_fallidos }}</dd></div>
                    <div class="dato">
                        <dt>Bloqueo</dt>
                        <dd>{{ $bloqueado ? 'Bloqueado hasta ' . $usuario->bloqueado_hasta->format('d/m/Y H:i') : 'No bloqueado' }}</dd>
                    </div>
                    <div class="dato"><dt>Debe cambiar la contraseña</dt><dd>{{ $usuario->debe_cambiar_password ? 'Sí' : 'No' }}</dd></div>
                </dl>

                {{-- CU04 A2 y A3 --}}
                <div class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-4">
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

                    <a href="{{ route('usuarios.listar', ['q' => $usuario->nombre_usuario]) }}" class="text-sm font-semibold text-teal-700 hover:underline">Modificar (en la lista)</a>
                </div>
            </div>

            {{-- Persona y perfiles --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Persona</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Nombre</dt><dd>{{ $persona->nombre_completo }}</dd></div>
                    <div class="dato"><dt>Documento</dt><dd>{{ $documento }}</dd></div>
                    <div class="dato"><dt>Fecha de nacimiento</dt><dd>{{ $persona->fecha_nacimiento?->format('d/m/Y') }} ({{ $persona->edad }} años)</dd></div>
                    <div class="dato"><dt>Teléfono</dt><dd>{{ $persona->telefono }}</dd></div>
                    <div class="dato"><dt>Correo de contacto</dt><dd>{{ $persona->correo ?? '—' }}</dd></div>
                </dl>

                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Perfil</th>
                                <th>Código</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($perfiles as $nombre => [$modelo, $enlace, $permiso])
                                <tr>
                                    <td>{{ $nombre }}</td>
                                    <td>
                                        @if (! $modelo)
                                            <span class="text-slate-400">No tiene</span>
                                        @elseif ($enlace && auth()->user()->can($permiso))
                                            <a href="{{ $enlace }}" class="text-teal-700 hover:underline">{{ $modelo->codigo }}</a>
                                        @else
                                            {{ $modelo->codigo }}
                                        @endif
                                    </td>
                                    <td>@if ($modelo) <x-estado :estado="$modelo->estado" /> @endif</td>
                                </tr>
                            @endforeach
                        </tbody>
    </table>
                </div>
            </div>

            {{-- Últimas 20 acciones en la bitácora --}}
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo">Últimas acciones</h2>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Acción</th>
                                <th>Módulo</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($acciones as $accion)
                                <tr>
                                    <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($accion->fecha_hora)->format('d/m/Y H:i:s') }}</td>
                                    <td>{{ $accion->accion }}</td>
                                    <td>{{ $accion->modulo }}</td>
                                    <td>
                                        <span @class(['font-semibold', 'text-green-700' => $accion->exito, 'text-red-700' => ! $accion->exito])>{{ $accion->exito ? 'Éxito' : 'Fallido' }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-8 text-center text-slate-500">Este usuario aún no tiene acciones registradas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
