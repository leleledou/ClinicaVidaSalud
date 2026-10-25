{{-- CU05 pasos 4 a 7: ficha del rol con sus permisos agrupados por módulo. --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Seguridad / Roles y permisos" :titulo="'Rol: ' . $rol->nombre">
            <a href="{{ route('roles.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            @if ($errors->any())
                <div class="alerta-error font-medium">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Datos del rol --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Datos del rol</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Nombre</dt><dd>{{ $rol->nombre }}</dd></div>
                    <div class="dato"><dt>Estado</dt><dd><x-estado :estado="$rol->estado" /></dd></div>
                    <div class="dato"><dt>Usuarios</dt><dd>{{ $rol->usuarios_count }}</dd></div>
                    <div class="dato"><dt>Permisos</dt><dd>{{ count($asignados) }}</dd></div>
                    <div class="dato xl:col-span-2"><dt>Descripción</dt><dd>{{ $rol->descripcion ?? '—' }}</dd></div>
                </dl>
                @if ($rol->estado !== 'ACTIVO')
                    <p class="alerta-aviso mt-4">El rol está INACTIVO: sus usuarios no tienen ninguno de estos permisos hasta que se active.</p>
                @endif
            </div>

            {{-- CU05 pasos 4 y 5: permisos por módulo; «Guardar permisos» envía el id oculto y permisos[] --}}
            <form method="POST" action="{{ route('roles.modificar') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="id" value="{{ $rol->id }}">
                <input type="hidden" name="guardar_permisos" value="1">

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    @foreach ($permisos as $modulo => $lista)
                        <section class="tarjeta space-y-3" data-modulo="{{ $modulo }}">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-semibold text-marino-900">{{ $modulos[$modulo] ?? ucfirst($modulo) }}</h3>
                                {{-- CU05 paso 4: marcar o desmarcar todo el módulo --}}
                                <div class="flex gap-3 text-xs">
                                    <button type="button" class="text-teal-700 hover:underline" onclick="marcarModulo(this, true)">Marcar todo</button>
                                    <button type="button" class="text-slate-600 hover:underline" onclick="marcarModulo(this, false)">Desmarcar todo</button>
                                </div>
                            </div>

                            <ul class="space-y-2">
                                @foreach ($lista as $permiso)
                                    <li>
                                        <label class="flex items-start gap-2 text-sm">
                                            <input type="checkbox" name="permisos[]" value="{{ $permiso->id }}" @checked(in_array($permiso->id, $asignados, true))
                                                   class="mt-0.5 rounded border-slate-300 text-teal-700 focus:ring-teal-500">
                                            <span>
                                                {{ $permiso->nombre }}
                                                <span class="text-xs text-slate-400">{{ $permiso->codigo }}</span>
                                                @if ($esRolPropio && in_array($permiso->codigo, $protegidos, true))
                                                    <span class="block text-xs text-amber-700">Es su rol: no puede quitarse este permiso.</span>
                                                @endif
                                            </span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                </div>

                <div class="flex items-center gap-4">
                    <x-primary-button>Guardar permisos</x-primary-button>
                    <span class="text-sm text-slate-600">Los usuarios del rol obtienen los cambios en su siguiente petición.</span>
                </div>
            </form>
        </div>
    </div>

    {{-- CU05 paso 4: «Marcar todo» / «Desmarcar todo» de un módulo --}}
    <script>
        function marcarModulo(boton, marcar) {
            boton.closest('[data-modulo]').querySelectorAll('input[name="permisos[]"]').forEach(function (casilla) {
                casilla.checked = marcar;
            });
        }
    </script>
</x-app-layout>
