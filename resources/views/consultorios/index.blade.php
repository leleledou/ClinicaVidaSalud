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
        <x-encabezado ruta="Catálogos / Consultorios" titulo="Gestionar consultorios" />
    </x-slot>

    <div class="space-y-6"
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
        <div class="space-y-6">
            {{-- CU08 A1 y otros errores de desactivar/activar (no son del formulario) --}}
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
            {{-- CU08 pasos 1-2: lista --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Consultorios registrados</h2>
                        {{-- CU08 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo consultorio</x-primary-button>
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Ubicación</th>
                                    <th>Estado</th>
                                    <th>Horarios activos</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($consultorios as $consultorio)
                                    <tr class="align-top">
                                        <td>
                                            <a href="{{ route('consultorios.mostrar', $consultorio->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">
                                                {{ $consultorio->nombre }}
                                            </a>
                                        </td>
                                        <td class="text-slate-600">{{ $consultorio->ubicacion }}</td>
                                        <td><x-estado :estado="$consultorio->estado" /></td>
                                        <td>{{ $consultorio->horarios_activos_count }}</td>
                                        <td>
                                            <div class="acciones">
                                                <button type="button" class="accion" @click="editar({{ Js::from($consultorio->only(['id', 'nombre', 'ubicacion'])) }})">
                                                    Modificar
                                                </button>

                                                @if ($consultorio->estado === 'ACTIVO')
                                                    {{-- CU08 A1: desactivar con confirmación; el id viaja oculto --}}
                                                    <form method="POST" action="{{ route('consultorios.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar el consultorio '.$consultorio->nombre.'?') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $consultorio->id }}">
                                                        <button type="submit" class="accion-peligro">Desactivar</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('consultorios.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $consultorio->id }}">
                                                        <button type="submit" class="accion">Activar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-8 text-center text-slate-500">No hay consultorios registrados.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- CU08 paso 3: formulario de registro y modificación --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                <section>
                    <header>
                        <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar consultorio' : 'Nuevo consultorio'"></h2>
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
                            <p class="js-error" data-campo="nombre" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="ubicacion" value="Ubicación" />
                            <x-text-input id="ubicacion" name="ubicacion" type="text" class="mt-1 block w-full" x-model="form.ubicacion" maxlength="255" placeholder="Por ejemplo, Primer piso, ala norte" />
                            <p class="js-error" data-campo="ubicacion" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('ubicacion')" class="mt-2" /> @endif
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
