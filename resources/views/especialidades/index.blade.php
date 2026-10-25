{{-- CU06 Gestionar especialidades (RF04): lista y formulario de registrar/modificar en la misma página. --}}
@php
    // Campos del formulario: si el servidor devolvió errores en alguno, el formulario se vuelve a abrir con lo escrito.
    $camposFormulario = ['nombre', 'descripcion', 'edad_minima', 'edad_maxima', 'sexo_permitido', 'duracion_sugerida_min'];
    $volvioFormulario = $errors->hasAny($camposFormulario);

    $formularioInicial = $volvioFormulario
        ? [
            'id' => old('id'),
            'nombre' => old('nombre', ''),
            'descripcion' => old('descripcion', ''),
            'edad_minima' => old('edad_minima', ''),
            'edad_maxima' => old('edad_maxima', ''),
            'sexo_permitido' => old('sexo_permitido', ''),
            'duracion_sugerida_min' => old('duracion_sugerida_min', ''),
        ]
        : null;

    // CU06 paso 2: textos del rango de edad y del sexo permitido.
    $textoEdad = function ($especialidad) {
        $min = $especialidad->edad_minima;
        $max = $especialidad->edad_maxima;
        if ($min === null && $max === null) return 'Todas las edades';
        if ($max === null) return "desde {$min} años";
        if ($min === null) return "hasta {$max} años";
        return "{$min} a {$max} años";
    };
    $textoSexo = fn ($sexo) => match ($sexo) { 'F' => 'Solo mujeres', 'M' => 'Solo hombres', default => 'Ambos' };
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Catálogos / Especialidades" titulo="Gestionar especialidades" />
    </x-slot>

    <div class="space-y-6"
         x-data="{
            abierto: {{ Js::from($volvioFormulario) }},
            vacio: { id: null, nombre: '', descripcion: '', edad_minima: '', edad_maxima: '', sexo_permitido: '', duracion_sugerida_min: '' },
            form: {},
            init() { this.form = {{ Js::from($formularioInicial) }} ?? { ...this.vacio }; },
            nuevo() { this.form = { ...this.vacio }; this.mostrar(); },
            editar(especialidad) {
                // null -> '' para que los campos vacíos se muestren vacíos
                this.form = Object.fromEntries(Object.entries(especialidad).map(([k, v]) => [k, v ?? '']));
                this.mostrar();
            },
            mostrar() {
                this.abierto = true;
                limpiarErroresJs(this.$refs.formulario);
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
            {{-- CU06 pasos 1-2: lista --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Especialidades registradas</h2>
                        {{-- CU06 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nueva especialidad</x-primary-button>
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Descripción</th>
                                    <th>Edad</th>
                                    <th>Sexo</th>
                                    <th>Duración</th>
                                    <th>Estado</th>
                                    <th>Médicos</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($especialidades as $especialidad)
                                    <tr class="align-top">
                                        <td>
                                            <a href="{{ route('especialidades.mostrar', $especialidad->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">
                                                {{ $especialidad->nombre }}
                                            </a>
                                        </td>
                                        <td class="text-slate-600">{{ $especialidad->descripcion ?? '—' }}</td>
                                        <td class="whitespace-nowrap">{{ $textoEdad($especialidad) }}</td>
                                        <td class="whitespace-nowrap">{{ $textoSexo($especialidad->sexo_permitido) }}</td>
                                        <td class="whitespace-nowrap">{{ $especialidad->duracion_sugerida_min }} min</td>
                                        <td>
                                            <x-estado :estado="$especialidad->estado" />
                                        </td>
                                        <td>{{ $especialidad->medicos_count }}</td>
                                        <td>
                                            <div class="acciones">
                                                {{-- CU06 A1 --}}
                                                <button type="button" class="accion" @click="editar({{ Js::from($especialidad->only(['id', 'nombre', 'descripcion', 'edad_minima', 'edad_maxima', 'sexo_permitido', 'duracion_sugerida_min'])) }})">
                                                    Modificar
                                                </button>

                                                @if ($especialidad->estado === 'ACTIVO')
                                                    {{-- CU06 A2: desactivar con confirmación; el id viaja oculto --}}
                                                    <form method="POST" action="{{ route('especialidades.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar la especialidad '.$especialidad->nombre.'?') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $especialidad->id }}">
                                                        <button type="submit" class="accion-peligro">Desactivar</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('especialidades.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $especialidad->id }}">
                                                        <button type="submit" class="accion">Activar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-8 text-center text-slate-500">No hay especialidades registradas.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- CU06 paso 4 / A1: formulario de registro y modificación --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                <section>
                    <header>
                        <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar especialidad' : 'Nueva especialidad'"></h2>
                    </header>

                    {{-- novalidate: la validación del navegador la hace validarEspecialidad() (más abajo), en español --}}
                    <form method="POST" novalidate
                          :action="form.id ? {{ Js::from(route('especialidades.modificar')) }} : {{ Js::from(route('especialidades.guardar')) }}"
                          @submit="if (! validarEspecialidad($event.target)) $event.preventDefault()"
                          class="mt-6 space-y-6">
                        @csrf
                        {{-- CU06 A1: el id viaja oculto solo al modificar --}}
                        <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                        <div>
                            <x-input-label for="nombre" value="Nombre" />
                            <x-text-input id="nombre" name="nombre" type="text" class="mt-1 block w-full" x-model="form.nombre" maxlength="255" />
                            <p class="js-error" data-campo="nombre" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombre')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="descripcion" value="Descripción (opcional)" />
                            <textarea id="descripcion" name="descripcion" rows="3" x-model="form.descripcion" maxlength="2000"
                                      class="campo"></textarea>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('descripcion')" class="mt-2" /> @endif
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <x-input-label for="edad_minima" value="Edad mínima (opcional)" />
                                <x-text-input id="edad_minima" name="edad_minima" type="number" min="0" max="120" step="1" class="mt-1 block w-full" x-model="form.edad_minima" />
                                <p class="js-error" data-campo="edad_minima" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('edad_minima')" class="mt-2" /> @endif
                            </div>
                            <div>
                                <x-input-label for="edad_maxima" value="Edad máxima (opcional)" />
                                <x-text-input id="edad_maxima" name="edad_maxima" type="number" min="0" max="120" step="1" class="mt-1 block w-full" x-model="form.edad_maxima" />
                                <p class="js-error" data-campo="edad_maxima" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('edad_maxima')" class="mt-2" /> @endif
                            </div>
                        </div>

                        <div>
                            <x-input-label for="sexo_permitido" value="Sexo permitido" />
                            <select id="sexo_permitido" name="sexo_permitido" x-model="form.sexo_permitido"
                                    class="campo">
                                <option value="">Ambos</option>
                                <option value="F">Solo mujeres (F)</option>
                                <option value="M">Solo hombres (M)</option>
                            </select>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('sexo_permitido')" class="mt-2" /> @endif
                        </div>

                        <div>
                            <x-input-label for="duracion_sugerida_min" value="Duración sugerida (minutos)" />
                            <x-text-input id="duracion_sugerida_min" name="duracion_sugerida_min" type="number" min="10" max="120" step="1" class="mt-1 block w-full" x-model="form.duracion_sugerida_min" />
                            <p class="js-error" data-campo="duracion_sugerida_min" hidden></p>
                            @if ($volvioFormulario) <x-input-error :messages="$errors->get('duracion_sugerida_min')" class="mt-2" /> @endif
                        </div>

                        {{-- CU06 A1: nota al modificar --}}
                        <p x-show="form.id" class="alerta-info">
                            Los cambios de edad o sexo se aplican a las citas nuevas, no a las ya registradas
                        </p>

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

    {{-- Validación en el cliente (CU06 pasos 5 y 6). El servidor vuelve a validar lo mismo. --}}
    <script>
        // Quita los mensajes de una validación anterior.
        function limpiarErroresJs(contenedor) {
            contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        }

        // Entero dentro de un rango; '' = vacío.
        function enteroEnRango(texto, min, max) {
            return /^\d+$/.test(texto) && Number(texto) >= min && Number(texto) <= max;
        }

        function validarEspecialidad(form) {
            limpiarErroresJs(form);
            const valor = (campo) => form.elements[campo].value.trim();
            const errores = {};

            if (valor('nombre') === '') {
                errores.nombre = 'El campo Nombre es obligatorio.';
            }
            if (valor('edad_minima') !== '' && ! enteroEnRango(valor('edad_minima'), 0, 120)) {
                errores.edad_minima = 'La edad mínima debe estar entre 0 y 120 años.';
            }
            if (valor('edad_maxima') !== '' && ! enteroEnRango(valor('edad_maxima'), 0, 120)) {
                errores.edad_maxima = 'La edad máxima debe estar entre 0 y 120 años.';
            }
            if (! errores.edad_minima && ! errores.edad_maxima && valor('edad_minima') !== '' && valor('edad_maxima') !== ''
                && Number(valor('edad_minima')) > Number(valor('edad_maxima'))) {
                errores.edad_minima = 'La edad mínima no puede ser mayor que la edad máxima';
            }
            if (valor('duracion_sugerida_min') === '') {
                errores.duracion_sugerida_min = 'El campo Duración sugerida es obligatorio.';
            } else if (! enteroEnRango(valor('duracion_sugerida_min'), 10, 120)) {
                errores.duracion_sugerida_min = 'La duración sugerida debe estar entre 10 y 120 minutos.';
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
