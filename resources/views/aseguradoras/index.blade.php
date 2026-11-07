{{-- CU23 Gestionar aseguradoras (RF-13): buscador, lista y formulario de registrar/modificar en la misma página. --}}
@php
    // Campos del formulario: si el servidor devolvió errores en alguno, el formulario se vuelve a abrir con lo escrito.
    $camposFormulario = ['nombre', 'nit', 'telefono'];
    $volvioFormulario = $errors->hasAny($camposFormulario);

    $formularioInicial = $volvioFormulario
        ? ['id' => old('id'), 'nombre' => old('nombre', ''), 'nit' => old('nit', ''), 'telefono' => old('telefono', '')]
        : null;
    $hayFiltros = $texto !== '' || $estado !== null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Catálogos / Aseguradoras" titulo="Gestionar aseguradoras" />
    </x-slot>

    <div class="space-y-6"
         x-data="{
            abierto: {{ Js::from($volvioFormulario) }},
            vacio: { id: null, nombre: '', nit: '', telefono: '' },
            form: {},
            init() { this.form = {{ Js::from($formularioInicial) }} ?? { ...this.vacio }; },
            nuevo() { this.form = { ...this.vacio }; this.mostrar(); },
            editar(aseguradora) {
                // null -> '' para que los campos vacíos se muestren vacíos
                this.form = Object.fromEntries(Object.entries(aseguradora).map(([k, v]) => [k, v ?? '']));
                this.mostrar();
            },
            mostrar() {
                this.abierto = true;
                limpiarErroresJs(this.$refs.formulario);
                this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
            },
         }">
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

        {{-- CU23 paso 2: búsqueda por nombre o NIT y filtro por estado --}}
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Filtros</h2>
            <form method="GET" action="{{ route('aseguradoras.listar') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <x-input-label for="q" value="Nombre o NIT" />
                    <x-text-input id="q" name="q" type="search" :value="$texto" placeholder="Por ejemplo, Andinos o 1020304011" />
                </div>
                <div>
                    <x-input-label for="estado" value="Estado" />
                    <select id="estado" name="estado" class="campo">
                        <option value="">Todos</option>
                        @foreach (['ACTIVO', 'INACTIVO'] as $valor)
                            <option value="{{ $valor }}" @selected($estado === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <x-primary-button>Buscar</x-primary-button>
                    @if ($hayFiltros)
                        <a href="{{ route('aseguradoras.listar') }}" class="btn-secundario">Limpiar</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- CU23 pasos 1-2: lista --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Aseguradoras con convenio</h2>
                        {{-- CU23 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nueva aseguradora</x-primary-button>
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>NIT</th>
                                    <th>Teléfono</th>
                                    <th>Estado</th>
                                    <th>Pólizas activas</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($aseguradoras as $aseguradora)
                                    <tr class="align-top">
                                        <td>
                                            <a href="{{ route('aseguradoras.mostrar', $aseguradora->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">
                                                {{ $aseguradora->nombre }}
                                            </a>
                                        </td>
                                        <td class="whitespace-nowrap">{{ $aseguradora->nit }}</td>
                                        <td class="whitespace-nowrap">{{ $aseguradora->telefono ?? '—' }}</td>
                                        <td><x-estado :estado="$aseguradora->estado" /></td>
                                        <td>{{ $aseguradora->polizas_activas_count }}</td>
                                        <td>
                                            <div class="acciones">
                                                {{-- CU23 A1 --}}
                                                <button type="button" class="accion" @click="editar({{ Js::from($aseguradora->only(['id', 'nombre', 'nit', 'telefono'])) }})">
                                                    Modificar
                                                </button>

                                                @if ($aseguradora->estado === 'ACTIVO')
                                                    {{-- CU23 A2: desactivar con confirmación; el id viaja oculto --}}
                                                    @php
                                                        $pregunta = '¿Desactivar la aseguradora ' . $aseguradora->nombre . '?';
                                                        if ($aseguradora->polizas_activas_count > 0) {
                                                            $pregunta .= ' Tiene ' . $aseguradora->polizas_activas_count . ' póliza(s) activa(s) que ya no servirán para citas nuevas.';
                                                        }
                                                    @endphp
                                                    <form method="POST" action="{{ route('aseguradoras.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from($pregunta) }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $aseguradora->id }}">
                                                        <button type="submit" class="accion-peligro">Desactivar</button>
                                                    </form>
                                                @else
                                                    {{-- CU23 A3 --}}
                                                    <form method="POST" action="{{ route('aseguradoras.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $aseguradora->id }}">
                                                        <button type="submit" class="accion">Activar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="py-8 text-center text-slate-500">
                                            {{ $hayFiltros ? 'No se encontraron aseguradoras con esos filtros.' : 'No hay aseguradoras registradas.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$aseguradoras" />
                </div>
            </div>

            {{-- CU23 paso 4 / A1: formulario de registro y modificación --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                <section>
                    <header>
                        <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar aseguradora' : 'Nueva aseguradora'"></h2>
                    </header>

                    {{-- novalidate: la validación del navegador la hace validarAseguradora() (más abajo), en español --}}
                    <form method="POST" novalidate
                          :action="form.id ? {{ Js::from(route('aseguradoras.modificar')) }} : {{ Js::from(route('aseguradoras.guardar')) }}"
                          @submit="if (! validarAseguradora($event.target)) $event.preventDefault()"
                          class="mt-6 space-y-6">
                        @csrf
                        {{-- CU23 A1: el id viaja oculto solo al modificar --}}
                        <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                        <div>
                            <x-input-label for="nombre" value="Nombre" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full" x-model="form.nombre" maxlength="255" />
                            <p class="js-error" data-campo="nombre" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="nit" value="NIT" />
                            <x-text-input id="nit" name="nit" type="text" inputmode="numeric" class="mt-1 block w-full" x-model="form.nit" maxlength="30" />
                            <p class="js-error" data-campo="nit" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nit')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="telefono" value="Teléfono (opcional)" />
                            <x-text-input id="telefono" name="telefono" type="text" class="mt-1 block w-full" x-model="form.telefono" maxlength="20" />
                            <p class="js-error" data-campo="telefono" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('telefono')" class="mt-2" /> @endif
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

    {{-- Validación en el cliente (CU23 paso 4). El servidor vuelve a validar lo mismo. --}}
    <script>
        // Quita los mensajes de una validación anterior.
        function limpiarErroresJs(contenedor) {
            contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        }

        function validarAseguradora(form) {
            limpiarErroresJs(form);
            const valor = (campo) => form.elements[campo].value.trim();
            const errores = {};

            if (valor('nombre') === '') {
                errores.nombre = 'El campo Nombre es obligatorio.';
            }
            if (valor('nit') === '') {
                errores.nit = 'El campo NIT es obligatorio.';
            } else if (! /^\d+$/.test(valor('nit'))) {
                errores.nit = 'El NIT solo puede tener dígitos.';
            }
            // Mismo formato que Persona::FORMATO_TELEFONO.
            if (valor('telefono') !== '' && ! /^[0-9+\-\s]{7,20}$/.test(valor('telefono'))) {
                errores.telefono = 'El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).';
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
