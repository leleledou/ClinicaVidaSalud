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
        <x-encabezado ruta="Seguridad / Roles y permisos" titulo="Roles y permisos" />
    </x-slot>

    <div class="space-y-6"
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
        <div class="space-y-6">
            {{-- Errores de desactivar/activar (no son del formulario) --}}
            @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
                <div class="alerta-error font-medium">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- CU05 paso 2: lista --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Roles registrados</h2>
                        {{-- CU05 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo rol</x-primary-button>
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Descripción</th>
                                    <th>Estado</th>
                                    <th>Usuarios</th>
                                    <th>Permisos</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($roles as $rol)
                                    <tr class="align-top">
                                        <td>
                                            {{-- CU05 paso 4: la ficha muestra y edita sus permisos --}}
                                            <a href="{{ route('roles.mostrar', $rol->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">{{ $rol->nombre }}</a>
                                            @if ($rol->id === $rolPropio)
                                                <span class="ms-1 text-xs text-slate-500">(su rol)</span>
                                            @endif
                                        </td>
                                        <td class="text-slate-600">{{ $rol->descripcion ?? '—' }}</td>
                                        <td><x-estado :estado="$rol->estado" /></td>
                                        <td>{{ $rol->usuarios_count }}</td>
                                        <td>{{ $rol->permisos_count }}</td>
                                        <td>
                                            <div class="acciones">
                                                <a href="{{ route('roles.mostrar', $rol->id) }}" class="accion">Permisos</a>
                                                <button type="button" class="accion" @click="editar({{ Js::from($rol->only(['id', 'nombre', 'descripcion'])) }})">Modificar</button>

                                                {{-- CU05 A1. Protección: su propio rol no se desactiva --}}
                                                @if ($rol->estado === 'ACTIVO' && $rol->id !== $rolPropio)
                                                    <form method="POST" action="{{ route('roles.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar el rol '.$rol->nombre.'? Sus '.$rol->usuarios_count.' usuario(s) perderán los permisos del rol.') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $rol->id }}">
                                                        <button type="submit" class="accion-peligro">Desactivar</button>
                                                    </form>
                                                @elseif ($rol->estado !== 'ACTIVO')
                                                    <form method="POST" action="{{ route('roles.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $rol->id }}">
                                                        <button type="submit" class="accion">Activar</button>
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
            <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                <section>
                    <header class="space-y-3">
                        <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar rol' : 'Nuevo rol'"></h2>
                        <p class="alerta-info">El nombre se guarda en mayúsculas. Los permisos se marcan en la ficha del rol.</p>
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
                            <p class="js-error" data-campo="nombre" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="descripcion" value="Descripción (opcional)" />
                            <textarea id="descripcion" name="descripcion" rows="3" maxlength="2000" x-model="form.descripcion"
                                      class="campo"></textarea>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('descripcion')" class="mt-2" /> @endif
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                            <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                        </div>
                    </form>
                </section>
            </div>
            </div>
        </div>
    </div>
</x-app-layout>
