{{-- CU04 Gestionar usuarios: búsqueda, lista y formulario de crear/modificar cuentas en la misma página. --}}
@php
    $camposFormulario = ['tipo_documento', 'numero_documento', 'complemento', 'expedido', 'nombres', 'apellido_paterno', 'apellido_materno',
        'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion', 'rol_id', 'turno', 'fecha_ingreso', 'cargo', 'nombre_usuario', 'correo_acceso'];
    $volvioFormulario = $errors->hasAny($camposFormulario);

    $vacio = array_merge(array_fill_keys($camposFormulario, ''), ['id' => null, 'tipo_documento' => 'CI', 'perfiles' => null, 'persona_nombre' => '']);

    // Perfiles de la persona (deciden qué campos del rol se piden y RN25).
    $perfil = fn ($modelo) => $modelo ? ['codigo' => $modelo->codigo, 'estado' => $modelo->estado] : null;
    $perfiles = fn ($persona) => [
        'medico' => $perfil($persona->medico),
        'paciente' => $perfil($persona->paciente),
        'recepcionista' => $perfil($persona->recepcionista),
        'administrador' => $perfil($persona->administrador),
    ];

    // Datos de una cuenta para el botón Modificar (CU04 A1: usuario, correo y rol).
    $datosEditar = fn ($usuario) => array_merge($vacio, [
        'id' => $usuario->id,
        'nombre_usuario' => $usuario->nombre_usuario,
        'correo_acceso' => $usuario->correo,
        'rol_id' => (string) $usuario->rol_id,
        'perfiles' => $perfiles($usuario->persona),
        'persona_nombre' => $usuario->persona->nombre_completo,
    ]);

    $inicial = null;
    if ($volvioFormulario) {
        $inicial = array_merge($vacio, array_map(fn ($valor) => $valor ?? '', \Illuminate\Support\Arr::only(old(), $camposFormulario)), ['id' => old('id')]);
        if ($editado) {
            $inicial['perfiles'] = $perfiles($editado->persona);
            $inicial['persona_nombre'] = $editado->persona->nombre_completo;
        }
    }

    $mapaRoles = $roles->pluck('nombre', 'id');
    $propio = auth()->id();
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Usuarios
        </h2>
    </x-slot>

    <div class="py-12"
         x-data="formularioPersona({
            vacio: {{ Js::from($vacio) }},
            inicial: {{ Js::from($inicial) }},
            abierto: {{ Js::from($volvioFormulario) }},
            perfil: 'usuario',
            edadMayoria: {{ $edadMayoria }},
            urlBuscar: {{ Js::from(route('personas.buscar')) }},
            urlFichaPaciente: '',
            roles: {{ Js::from($mapaRoles) }},
         })">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- Errores de desactivar/activar (no son del formulario) --}}
            @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 space-y-4">
                    {{-- CU04 paso 2: búsqueda y filtros --}}
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <form method="GET" action="{{ route('usuarios.listar') }}" class="flex flex-wrap items-end gap-3">
                            <div>
                                <x-input-label for="q" value="Nombre, usuario o correo" />
                                <x-text-input id="q" name="q" type="search" class="mt-1 block w-64" :value="$texto" />
                            </div>
                            <div>
                                <x-input-label for="filtro_rol" value="Rol" />
                                <select id="filtro_rol" name="rol_id" class="mt-1 block w-44 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="">Todos</option>
                                    @foreach ($roles as $rol)
                                        <option value="{{ $rol->id }}" @selected($rolId === $rol->id)>{{ $rol->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="filtro_estado" value="Estado" />
                                <select id="filtro_estado" name="estado" class="mt-1 block w-36 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="">Todos</option>
                                    <option value="ACTIVO" @selected($estado === 'ACTIVO')>Activo</option>
                                    <option value="INACTIVO" @selected($estado === 'INACTIVO')>Inactivo</option>
                                </select>
                            </div>
                            <x-primary-button>Buscar</x-primary-button>
                            @if ($texto !== '' || $rolId || $estado)
                                <a href="{{ route('usuarios.listar') }}" class="text-sm text-gray-600 hover:underline">Limpiar</a>
                            @endif
                        </form>

                        {{-- CU04 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo usuario</x-primary-button>
                    </div>

                    {{-- CU04 paso 2: lista --}}
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Nombre</th>
                                    <th class="px-3 py-2">Usuario</th>
                                    <th class="px-3 py-2">Correo</th>
                                    <th class="px-3 py-2">Rol</th>
                                    <th class="px-3 py-2">Estado</th>
                                    <th class="px-3 py-2">Último acceso</th>
                                    <th class="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($usuarios as $usuario)
                                    @php $bloqueado = $usuario->bloqueado_hasta?->isFuture(); @endphp
                                    <tr class="align-top">
                                        <td class="px-3 py-3">
                                            <a href="{{ route('usuarios.mostrar', $usuario->id) }}" class="font-medium text-indigo-600 hover:underline">{{ $usuario->persona->nombre_completo }}</a>
                                            @if ($usuario->id === $propio) <span class="ms-1 text-xs text-gray-500">(usted)</span> @endif
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $usuario->nombre_usuario }}</td>
                                        <td class="px-3 py-3">{{ $usuario->correo }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $usuario->rol->nombre }}</td>
                                        <td class="px-3 py-3 space-y-1">
                                            <x-estado :estado="$usuario->estado" />
                                            {{-- CU04 paso 2: bloqueo por intentos fallidos (CU01 A1) --}}
                                            @if ($bloqueado)
                                                <span class="block whitespace-nowrap text-xs font-semibold text-red-700">Bloqueado hasta {{ $usuario->bloqueado_hasta->format('d/m H:i') }}</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $usuario->ultimo_acceso?->format('d/m/Y H:i') ?? 'Nunca' }}</td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                {{-- CU04 A1 --}}
                                                <x-secondary-button @click="editar({{ Js::from($datosEditar($usuario)) }})">Modificar</x-secondary-button>

                                                {{-- CU04 A2 --}}
                                                @if ($bloqueado)
                                                    <form method="POST" action="{{ route('usuarios.desbloquear') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $usuario->id }}">
                                                        <x-secondary-button type="submit">Desbloquear</x-secondary-button>
                                                    </form>
                                                @endif

                                                {{-- CU04 A3. Protección: no puede desactivarse a sí mismo --}}
                                                @if ($usuario->estado === 'ACTIVO' && $usuario->id !== $propio)
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
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-3 py-6 text-center text-gray-500">No se encontraron usuarios.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$usuarios" />
                </div>
            </div>

            {{-- CU04 pasos 3 a 7 / A1: formulario --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <section class="max-w-4xl space-y-6">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900" x-text="form.id ? 'Modificar usuario' : 'Nuevo usuario'"></h2>
                        <p class="mt-1 text-sm text-gray-600" x-show="! form.id">Busque primero a la persona por su documento: si ya está registrada, se reutilizan sus datos.</p>
                        <p class="mt-1 text-sm text-gray-600" x-show="form.id">Persona: <span class="font-medium" x-text="form.persona_nombre"></span></p>
                    </header>

                    {{-- novalidate: la validación la hace validarUsuario() (resources/js/personas.js), en español --}}
                    <form method="POST" novalidate class="space-y-6"
                          :action="form.id ? {{ Js::from(route('usuarios.modificar')) }} : {{ Js::from(route('usuarios.guardar')) }}"
                          @submit="if (! validarUsuario($event.target, {{ $edadMayoria }}, $data)) $event.preventDefault()">
                        @csrf
                        {{-- El id viaja oculto solo al modificar --}}
                        <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                        {{-- CU04 paso 4: la persona (solo al crear la cuenta) --}}
                        <div x-show="! form.id" class="space-y-6">
                            @include('personas._campos', ['perfil' => 'usuario', 'volvioFormulario' => $volvioFormulario])
                        </div>

                        {{-- CU04 paso 5: rol y datos del perfil que se crea con él --}}
                        <fieldset class="space-y-4">
                            <legend class="text-sm font-semibold text-gray-700">Rol</legend>
                            <div>
                                <x-input-label for="rol_id" value="Rol" />
                                <select id="rol_id" name="rol_id" x-model="form.rol_id" class="mt-1 block w-full sm:w-80 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="">Seleccione…</option>
                                    @foreach ($roles->where('estado', 'ACTIVO') as $rol)
                                        <option value="{{ $rol->id }}">{{ $rol->nombre }}</option>
                                    @endforeach
                                </select>
                                <p class="js-error mt-2 text-sm text-red-600" data-campo="rol_id" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('rol_id')" class="mt-2" /> @endif
                            </div>

                            {{-- RN25: los roles profesionales exigen su perfil ACTIVO --}}
                            <p x-show="rolElegido === 'MEDICO'" class="text-sm text-gray-600">El rol MEDICO exige que la persona tenga el perfil de médico ACTIVO (se registra en Médicos).</p>
                            <p x-show="rolElegido === 'PACIENTE'" class="text-sm text-gray-600">El rol PACIENTE exige que la persona tenga el perfil de paciente ACTIVO (se registra en Pacientes).</p>

                            <div x-show="rolElegido === 'RECEPCIONISTA' && ! perfilesPersona?.recepcionista" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="turno" value="Turno" />
                                    <select id="turno" name="turno" x-model="form.turno" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="">Seleccione…</option>
                                        @foreach ($turnos as $valor => $nombreTurno)
                                            <option value="{{ $valor }}">{{ $nombreTurno }}</option>
                                        @endforeach
                                    </select>
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="turno" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('turno')" class="mt-2" /> @endif
                                </div>
                                <div>
                                    <x-input-label for="fecha_ingreso" value="Fecha de ingreso" />
                                    <x-text-input id="fecha_ingreso" name="fecha_ingreso" type="date" max="{{ today()->toDateString() }}" class="mt-1 block w-full" x-model="form.fecha_ingreso" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="fecha_ingreso" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('fecha_ingreso')" class="mt-2" /> @endif
                                </div>
                                <p class="sm:col-span-2 text-sm text-gray-600">Se creará su perfil de recepcionista (código REC-…).</p>
                            </div>

                            <div x-show="rolElegido === 'ADMINISTRADOR' && ! perfilesPersona?.administrador">
                                <x-input-label for="cargo" value="Cargo" />
                                <x-text-input id="cargo" name="cargo" type="text" maxlength="255" class="mt-1 block w-full sm:w-80" x-model="form.cargo" placeholder="Por ejemplo, Jefe de Operaciones" />
                                <p class="js-error mt-2 text-sm text-red-600" data-campo="cargo" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('cargo')" class="mt-2" /> @endif
                                <p class="mt-1 text-sm text-gray-600">Se creará su perfil de administrador (código ADM-…).</p>
                            </div>
                        </fieldset>

                        {{-- CU04 paso 6: cuenta de acceso --}}
                        <fieldset class="space-y-4">
                            <legend class="text-sm font-semibold text-gray-700">Cuenta de acceso</legend>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="nombre_usuario" value="Nombre de usuario" />
                                    <x-text-input id="nombre_usuario" name="nombre_usuario" type="text" maxlength="50" class="mt-1 block w-full" x-model="form.nombre_usuario" autocomplete="off" placeholder="Por ejemplo, jperez" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="nombre_usuario" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre_usuario')" class="mt-2" /> @endif
                                </div>
                                <div>
                                    <x-input-label for="correo_acceso" value="Correo de acceso" />
                                    <x-text-input id="correo_acceso" name="correo_acceso" type="email" maxlength="255" class="mt-1 block w-full" x-model="form.correo_acceso" autocomplete="off" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="correo_acceso" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('correo_acceso')" class="mt-2" /> @endif
                                </div>
                            </div>
                            <p x-show="! form.id" class="text-sm text-gray-600">
                                El sistema genera una contraseña temporal y la envía al correo de acceso. El usuario deberá cambiarla al ingresar.
                            </p>
                        </fieldset>

                        <div class="flex items-center gap-4">
                            <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Crear usuario'">Guardar</x-primary-button>
                            <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
