{{-- CU33 Gestionar catálogo de laboratorio (RF-34) paso 1: lista de exámenes y formulario de registrar/modificar
     en la misma página. Los parámetros y rangos se gestionan en la ficha de cada examen. --}}
@php
    $camposFormulario = ['codigo', 'nombre', 'tipo_muestra', 'indicaciones_previas'];
    $volvioFormulario = $errors->hasAny($camposFormulario);
    $vacio = ['id' => null, 'codigo' => '', 'nombre' => '', 'tipo_muestra' => '', 'indicaciones_previas' => ''];
    $inicial = $volvioFormulario
        ? array_merge($vacio, array_map(fn ($v) => $v ?? '', \Illuminate\Support\Arr::only(old(), $camposFormulario)), ['id' => old('id')])
        : null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Laboratorio / Catálogo" titulo="Catálogo de laboratorio" />
    </x-slot>

    <div class="space-y-6"
         x-data="{
            abierto: {{ Js::from($volvioFormulario) }},
            vacio: {{ Js::from($vacio) }},
            form: {},
            init() { this.form = {{ Js::from($inicial) }} ?? { ...this.vacio }; },
            nuevo() { this.form = { ...this.vacio }; this.mostrar(); },
            editar(examen) {
                this.form = Object.fromEntries(Object.entries(examen).map(([k, v]) => [k, v ?? '']));
                this.mostrar();
            },
            mostrar() {
                this.abierto = true;
                limpiarErroresJs(this.$refs.formulario);
                this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
            },
         }">
        @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
            <div class="alerta-error font-medium">{{ $errors->first('operacion') ?: $errors->first('id') }}</div>
        @endif

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- CU33 paso 1: lista con la cantidad de parámetros --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Exámenes</h2>
                        <x-primary-button type="button" @click="nuevo()">Nuevo examen</x-primary-button>
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Tipo de muestra</th>
                                    <th>Parámetros</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($examenes as $examen)
                                    <tr class="align-top">
                                        <td class="whitespace-nowrap font-mono text-xs">{{ $examen->codigo }}</td>
                                        <td>
                                            <a href="{{ route('tipos-examen.mostrar', $examen->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">{{ $examen->nombre }}</a>
                                        </td>
                                        <td class="text-slate-600">{{ $examen->tipo_muestra }}</td>
                                        <td>
                                            {{ $examen->parametros_count }}
                                            @if ($examen->parametros_count === 0)
                                                <span class="block text-xs text-amber-700">Sin parámetros: no se puede solicitar</span>
                                            @endif
                                        </td>
                                        <td><x-estado :estado="$examen->estado" /></td>
                                        <td>
                                            <div class="acciones">
                                                <a href="{{ route('tipos-examen.mostrar', $examen->id) }}" class="accion">Parámetros y rangos</a>
                                                {{-- CU33 A1 --}}
                                                <button type="button" class="accion" @click="editar({{ Js::from($examen->only(['id', 'codigo', 'nombre', 'tipo_muestra', 'indicaciones_previas'])) }})">Modificar</button>
                                                {{-- CU33 A2: desactivar o activar; el id viaja oculto --}}
                                                @if ($examen->estado === 'ACTIVO')
                                                    <form method="POST" action="{{ route('tipos-examen.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar el examen ' . $examen->nombre . '? Ya no se podrá solicitar en órdenes nuevas.') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $examen->id }}">
                                                        <button type="submit" class="accion-peligro">Desactivar</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('tipos-examen.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $examen->id }}">
                                                        <button type="submit" class="accion">Activar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6" class="py-8 text-center text-slate-500">No hay exámenes registrados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- CU33 paso 1 / A1: formulario de registro y modificación --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                <section>
                    <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar examen' : 'Nuevo examen'"></h2>

                    {{-- novalidate: la validación del navegador la hace validarExamen() (más abajo), en español --}}
                    <form method="POST" novalidate
                          :action="form.id ? {{ Js::from(route('tipos-examen.modificar')) }} : {{ Js::from(route('tipos-examen.guardar')) }}"
                          @submit="if (! validarExamen($event.target)) $event.preventDefault()"
                          class="mt-6 space-y-5">
                        @csrf
                        <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                        <div>
                            <x-input-label for="codigo" value="Código" />
                            <x-text-input id="codigo" name="codigo" type="text" maxlength="30" class="uppercase" x-model="form.codigo" placeholder="PERFIL_LIPIDICO" />
                            <p class="js-error" data-campo="codigo" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('codigo')" class="mt-2" /> @endif
                        </div>
                        <div>
                            <x-input-label for="nombre" value="Nombre" />
                            <x-text-input id="nombre" name="nombre" type="text" maxlength="255" x-model="form.nombre" />
                            <p class="js-error" data-campo="nombre" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre')" class="mt-2" /> @endif
                        </div>
                        <div>
                            <x-input-label for="tipo_muestra" value="Tipo de muestra" />
                            <x-text-input id="tipo_muestra" name="tipo_muestra" type="text" maxlength="255" x-model="form.tipo_muestra" placeholder="Sangre venosa con EDTA" />
                            <p class="js-error" data-campo="tipo_muestra" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('tipo_muestra')" class="mt-2" /> @endif
                        </div>
                        <div>
                            <x-input-label for="indicaciones_previas" value="Indicaciones previas (opcional)" />
                            <textarea id="indicaciones_previas" name="indicaciones_previas" rows="2" maxlength="2000" class="campo" x-model="form.indicaciones_previas"
                                      placeholder="Ayuno de 8 a 12 horas."></textarea>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('indicaciones_previas')" class="mt-2" /> @endif
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

    {{-- Validación en el cliente (CU33 paso 1). El servidor vuelve a validar lo mismo. --}}
    <script>
        function limpiarErroresJs(contenedor) {
            contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        }

        function validarExamen(form) {
            limpiarErroresJs(form);
            const valor = (campo) => form.elements[campo].value.trim();
            const errores = {};
            const codigo = valor('codigo').replace(/\s+/g, '').toUpperCase();
            if (codigo === '') {
                errores.codigo = 'El campo Código es obligatorio.';
            } else if (! /^[A-Z0-9_]{2,30}$/.test(codigo)) {
                errores.codigo = 'El código solo puede tener letras mayúsculas, dígitos y guion bajo (de 2 a 30 caracteres), por ejemplo PERFIL_LIPIDICO.';
            }
            if (valor('nombre') === '') errores.nombre = 'El campo Nombre es obligatorio.';
            if (valor('tipo_muestra') === '') errores.tipo_muestra = 'El campo Tipo de muestra es obligatorio.';
            Object.entries(errores).forEach(function ([campo, mensaje]) {
                const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                p.textContent = mensaje;
                p.hidden = false;
            });
            return Object.keys(errores).length === 0;
        }
    </script>
</x-app-layout>
