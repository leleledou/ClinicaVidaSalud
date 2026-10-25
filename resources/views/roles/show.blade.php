{{-- CU05 pasos 4 a 7: ficha del rol con sus permisos agrupados por módulo. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Rol: {{ $rol->nombre }}
            </h2>
            <a href="{{ route('roles.listar') }}" class="text-sm text-indigo-600 hover:underline">Volver a la lista</a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->any())
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Datos del rol --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-4 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="font-medium text-gray-500">Nombre</dt><dd class="mt-1">{{ $rol->nombre }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Estado</dt><dd class="mt-1"><x-estado :estado="$rol->estado" /></dd></div>
                    <div><dt class="font-medium text-gray-500">Usuarios</dt><dd class="mt-1">{{ $rol->usuarios_count }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Permisos</dt><dd class="mt-1">{{ count($asignados) }}</dd></div>
                    <div class="sm:col-span-4"><dt class="font-medium text-gray-500">Descripción</dt><dd class="mt-1">{{ $rol->descripcion ?? '—' }}</dd></div>
                </dl>
                @if ($rol->estado !== 'ACTIVO')
                    <p class="mt-4 text-sm text-yellow-800">El rol está INACTIVO: sus usuarios no tienen ninguno de estos permisos hasta que se active.</p>
                @endif
            </div>

            {{-- CU05 pasos 4 y 5: permisos por módulo; «Guardar permisos» envía el id oculto y permisos[] --}}
            <form method="POST" action="{{ route('roles.modificar') }}" class="space-y-6">
                @csrf
                <input type="hidden" name="id" value="{{ $rol->id }}">
                <input type="hidden" name="guardar_permisos" value="1">

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    @foreach ($permisos as $modulo => $lista)
                        <section class="bg-white shadow-sm sm:rounded-lg p-6 space-y-3" data-modulo="{{ $modulo }}">
                            <div class="flex items-center justify-between">
                                <h3 class="text-base font-semibold text-gray-900">{{ $modulos[$modulo] ?? ucfirst($modulo) }}</h3>
                                {{-- CU05 paso 4: marcar o desmarcar todo el módulo --}}
                                <div class="flex gap-3 text-xs">
                                    <button type="button" class="text-indigo-600 hover:underline" onclick="marcarModulo(this, true)">Marcar todo</button>
                                    <button type="button" class="text-gray-600 hover:underline" onclick="marcarModulo(this, false)">Desmarcar todo</button>
                                </div>
                            </div>

                            <ul class="space-y-2">
                                @foreach ($lista as $permiso)
                                    <li>
                                        <label class="flex items-start gap-2 text-sm">
                                            <input type="checkbox" name="permisos[]" value="{{ $permiso->id }}" @checked(in_array($permiso->id, $asignados, true))
                                                   class="mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            <span>
                                                {{ $permiso->nombre }}
                                                <span class="text-xs text-gray-400">{{ $permiso->codigo }}</span>
                                                @if ($esRolPropio && in_array($permiso->codigo, $protegidos, true))
                                                    <span class="block text-xs text-yellow-700">Es su rol: no puede quitarse este permiso.</span>
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
                    <span class="text-sm text-gray-600">Los usuarios del rol obtienen los cambios en su siguiente petición.</span>
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
