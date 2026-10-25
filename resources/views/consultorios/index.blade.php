{{-- CU08 Gestionar consultorios: lista y formulario de registrar/modificar en la misma página. --}}
@php
    // Si el servidor devolvió errores del formulario, se vuelve a abrir con lo escrito.
    $volvioFormulario = $errors->hasAny(['nombre', 'ubicacion']);

    $formularioInicial = $volvioFormulario
        ? ['id' => old('id'), 'nombre' => old('nombre', ''), 'ubicacion' => old('ubicacion', '')]
        : null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Gestionar consultorios
        </h2>
    </x-slot>

    <div class="py-12"
         x-data="{
            abierto: {{ Js::from($volvioFormulario) }},
            vacio: { id: null, nombre: '', ubicacion: '' },
            form: {},
            init() { this.form = {{ Js::from($formularioInicial) }} ?? { ...this.vacio }; },
            nuevo() { this.form = { ...this.vacio }; this.mostrar(); },
            editar(consultorio) { this.form = { ...consultorio }; this.mostrar(); },
            mostrar() {
                this.abierto = true;
                limpiarErroresJs(this.$refs.formulario);
                this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
            },
         }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU08 A1 y otros errores de desactivar/activar (no son del formulario) --}}
            @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- CU08 pasos 1-2: lista --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 space-y-4">
                    <div class="flex justify-end">
                        {{-- CU08 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo consultorio</x-primary-button>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Nombre</th>
                                    <th class="px-3 py-2">Ubicación</th>
                                    <th class="px-3 py-2">Estado</th>
                                    <th class="px-3 py-2">Horarios activos</th>
                                    <th class="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($consultorios as $consultorio)
                                    <tr class="align-top">
                                        <td class="px-3 py-3">
                                            <a href="{{ route('consultorios.mostrar', $consultorio->id) }}" class="font-medium text-indigo-600 hover:underline">
                                                {{ $consultorio->nombre }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-3 text-gray-600">{{ $consultorio->ubicacion }}</td>
                                        <td class="px-3 py-3"><x-estado :estado="$consultorio->estado" /></td>
                                        <td class="px-3 py-3">{{ $consultorio->horarios_activos_count }}</td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <x-secondary-button @click="editar({{ Js::from($consultorio->only(['id', 'nombre', 'ubicacion'])) }})">
                                                    Modificar
                                                </x-secondary-button>

                                                @if ($consultorio->estado === 'ACTIVO')
                                                    {{-- CU08 A1: desactivar con confirmación; el id viaja oculto --}}
                                                    <form method="POST" action="{{ route('consultorios.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar el consultorio '.$consultorio->nombre.'?') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $consultorio->id }}">
                                                        <x-danger-button>Desactivar</x-danger-button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('consultorios.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $consultorio->id }}">
                                                        <x-primary-button>Activar</x-primary-button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-3 py-6 text-center text-gray-500">No hay consultorios registrados.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- CU08 paso 3: formulario de registro y modificación --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <section class="max-w-xl">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900" x-text="form.id ? 'Modificar consultorio' : 'Nuevo consultorio'"></h2>
                    </header>

                    {{-- novalidate: la validación del navegador la hace validarConsultorio() (más abajo), en español --}}
                    <form method="POST" novalidate
                          :action="form.id ? {{ Js::from(route('consultorios.modificar')) }} : {{ Js::from(route('consultorios.guardar')) }}"
                          @submit="if (! validarConsultorio($event.target)) $event.preventDefault()"
                          class="mt-6 space-y-6">
                        @csrf
                        {{-- El id viaja oculto solo al modificar --}}
                        <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                        <div>
                            <x-input-label for="nombre" value="Nombre" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full" x-model="form.nombre" maxlength="255" placeholder="Por ejemplo, C-101" />
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="nombre" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="ubicacion" value="Ubicación" />
                            <x-text-input id="ubicacion" name="ubicacion" type="text" class="mt-1 block w-full" x-model="form.ubicacion" maxlength="255" placeholder="Por ejemplo, Primer piso, ala norte" />
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="ubicacion" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('ubicacion')" class="mt-2" /> @endif
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

    {{-- Validación en el cliente (CU08 pasos 3 y 4). El servidor vuelve a validar lo mismo. --}}
    <script>
        // Quita los mensajes de una validación anterior.
        function limpiarErroresJs(contenedor) {
            contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        }

        function validarConsultorio(form) {
            limpiarErroresJs(form);
            const errores = {};

            if (form.elements.nombre.value.trim() === '') {
                errores.nombre = 'El campo Nombre es obligatorio.';
            }
            if (form.elements.ubicacion.value.trim() === '') {
                errores.ubicacion = 'El campo Ubicación es obligatorio.';
            }

            // El mensaje se muestra junto a su campo.
            Object.entries(errores).forEach(function ([campo, mensaje]) {
                const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                p.textContent = mensaje;
                p.hidden = false;
            });

            return Object.keys(errores).length === 0;
        }
    </script>
</x-app-layout>
