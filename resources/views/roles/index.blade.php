{{-- CU05 Gestionar roles y permisos: lista y formulario de registrar/modificar en la misma página. --}}
@php
    // Si el servidor devolvió errores del formulario, se vuelve a abrir con lo escrito.
    $volvioFormulario = $errors->hasAny(['nombre', 'descripcion']);
    $formularioInicial = $volvioFormulario
        ? ['id' => old('id'), 'nombre' => old('nombre', ''), 'descripcion' => old('descripcion', '')]
        : null;
    $rolPropio = auth()->user()->rol_id;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Roles y permisos
        </h2>
    </x-slot>

    <div class="py-12"
         x-data="{
            abierto: {{ Js::from($volvioFormulario) }},
            form: {{ Js::from($formularioInicial) }} ?? { id: null, nombre: '', descripcion: '' },
            nuevo() { this.form = { id: null, nombre: '', descripcion: '' }; this.mostrar(); },
            editar(rol) { this.form = { ...rol, descripcion: rol.descripcion ?? '' }; this.mostrar(); },
            mostrar() {
                this.abierto = true;
                this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
            },
         }">
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

            {{-- CU05 paso 2: lista --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 space-y-4">
                    <div class="flex justify-end">
                        {{-- CU05 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo rol</x-primary-button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Nombre</th>
                                    <th class="px-3 py-2">Descripción</th>
                                    <th class="px-3 py-2">Estado</th>
                                    <th class="px-3 py-2">Usuarios</th>
                                    <th class="px-3 py-2">Permisos</th>
                                    <th class="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($roles as $rol)
                                    <tr class="align-top">
                                        <td class="px-3 py-3">
                                            {{-- CU05 paso 4: la ficha muestra y edita sus permisos --}}
                                            <a href="{{ route('roles.mostrar', $rol->id) }}" class="font-medium text-indigo-600 hover:underline">{{ $rol->nombre }}</a>
                                            @if ($rol->id === $rolPropio)
                                                <span class="ms-1 text-xs text-gray-500">(su rol)</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 text-gray-600">{{ $rol->descripcion ?? '—' }}</td>
                                        <td class="px-3 py-3"><x-estado :estado="$rol->estado" /></td>
                                        <td class="px-3 py-3">{{ $rol->usuarios_count }}</td>
                                        <td class="px-3 py-3">{{ $rol->permisos_count }}</td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <a href="{{ route('roles.mostrar', $rol->id) }}" class="inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md font-semibold text-xs text-gray-700 uppercase tracking-widest shadow-sm hover:bg-gray-50">Permisos</a>
                                                <x-secondary-button @click="editar({{ Js::from($rol->only(['id', 'nombre', 'descripcion'])) }})">Modificar</x-secondary-button>

                                                {{-- CU05 A1. Protección: su propio rol no se desactiva --}}
                                                @if ($rol->estado === 'ACTIVO' && $rol->id !== $rolPropio)
                                                    <form method="POST" action="{{ route('roles.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar el rol '.$rol->nombre.'? Sus '.$rol->usuarios_count.' usuario(s) perderán los permisos del rol.') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $rol->id }}">
                                                        <x-danger-button>Desactivar</x-danger-button>
                                                    </form>
                                                @elseif ($rol->estado !== 'ACTIVO')
                                                    <form method="POST" action="{{ route('roles.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $rol->id }}">
                                                        <x-primary-button>Activar</x-primary-button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- CU05 paso 3: formulario de registro y modificación --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <section class="max-w-xl">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900" x-text="form.id ? 'Modificar rol' : 'Nuevo rol'"></h2>
                        <p class="mt-1 text-sm text-gray-600">El nombre se guarda en mayúsculas. Los permisos se marcan en la ficha del rol.</p>
                    </header>

                    {{-- novalidate: la validación la hace validarRol() (resources/js/personas.js), en español --}}
                    <form method="POST" novalidate class="mt-6 space-y-6"
                          :action="form.id ? {{ Js::from(route('roles.modificar')) }} : {{ Js::from(route('roles.guardar')) }}"
                          @submit="if (! validarRol($event.target)) $event.preventDefault()">
                        @csrf
                        {{-- El id viaja oculto solo al modificar --}}
                        <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                        <div>
                            <x-input-label for="nombre" value="Nombre" />
                            <x-text-input id="nombre" name="nombre" type="text" maxlength="255" class="mt-1 block w-full uppercase" x-model="form.nombre" placeholder="Por ejemplo, SUPERVISOR" />
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="nombre" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="descripcion" value="Descripción (opcional)" />
                            <textarea id="descripcion" name="descripcion" rows="3" maxlength="2000" x-model="form.descripcion"
                                      class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('descripcion')" class="mt-2" /> @endif
                        </div>

                        <div class="flex items-center gap-4">
                            <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                            <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
