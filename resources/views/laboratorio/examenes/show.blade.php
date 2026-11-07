{{-- CU33 Gestionar catálogo de laboratorio (RF-34) pasos 2 y 3: ficha del examen con sus parámetros y, bajo
     cada parámetro NUMERICO, sus rangos de referencia por edad (meses) y sexo con vigencia. Los formularios
     están en la misma página; el que volvió con errores se reabre con old() (campo oculto «formulario»). --}}
@php
    $volvio = old('formulario');
    $parametroVacio = ['id' => null, 'nombre' => '', 'unidad' => '', 'tipo_valor' => 'NUMERICO', 'orden' => (string) (($examen->parametros->max('orden') ?? 0) + 1), 'con_resultados' => false];
    $rangoVacio = ['id' => null, 'parametro_laboratorio_id' => '', 'parametro' => '', 'edad_min_meses' => '', 'edad_max_meses' => '', 'sexo' => '',
        'valor_min' => '', 'valor_max' => '', 'critico_min' => '', 'critico_max' => '', 'vigente_desde' => today()->toDateString(), 'vigente_hasta' => ''];
    $parametroInicial = $volvio === 'parametro'
        ? array_merge($parametroVacio, array_map(fn ($v) => $v ?? '', \Illuminate\Support\Arr::only(old(), ['nombre', 'unidad', 'tipo_valor', 'orden'])),
            ['id' => old('id'), 'con_resultados' => (bool) old('con_resultados')])
        : null;
    $rangoInicial = $volvio === 'rango'
        ? array_merge($rangoVacio, array_map(fn ($v) => $v ?? '', \Illuminate\Support\Arr::only(old(), array_keys($rangoVacio))), ['id' => old('id')])
        : null;
    $errores = fn ($formulario, $campo) => $volvio === $formulario ? $errors->get($campo) : [];
    // Datos de un rango para el botón Modificar (decimales sin ceros sobrantes).
    $numero = fn ($v) => $v === null ? '' : rtrim(rtrim((string) $v, '0'), '.');
    $datosRango = fn ($rango, $parametro) => [
        'id' => $rango->id, 'parametro_laboratorio_id' => $parametro->id, 'parametro' => $parametro->nombre,
        'edad_min_meses' => (string) $rango->edad_min_meses, 'edad_max_meses' => (string) $rango->edad_max_meses, 'sexo' => $rango->sexo ?? '',
        'valor_min' => $numero($rango->valor_min), 'valor_max' => $numero($rango->valor_max), 'critico_min' => $numero($rango->critico_min), 'critico_max' => $numero($rango->critico_max),
        'vigente_desde' => $rango->vigente_desde->toDateString(), 'vigente_hasta' => $rango->vigente_hasta?->toDateString() ?? '',
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Laboratorio / Catálogo" :titulo="'Examen: ' . $examen->nombre">
            <a href="{{ route('tipos-examen.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver al catálogo</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6"
         x-data="{
            parametroAbierto: {{ Js::from($parametroInicial !== null) }},
            parametro: {{ Js::from($parametroInicial ?? $parametroVacio) }},
            rangoAbierto: {{ Js::from($rangoInicial !== null) }},
            rango: {{ Js::from($rangoInicial ?? $rangoVacio) }},
            abrir(nombre, datos, vacio) {
                this[nombre] = { ...vacio, ...datos };
                this[nombre + 'Abierto'] = true;
                limpiarErroresJs(this.$refs[nombre]);
                this.$nextTick(() => this.$refs[nombre].scrollIntoView({ behavior: 'smooth', block: 'center' }));
            },
         }">
        @if ($errors->hasAny(['operacion', 'id']))
            <div class="alerta-error font-medium">{{ $errors->first('operacion') ?: $errors->first('id') }}</div>
        @endif

        {{-- Datos del examen (se modifican en el catálogo) --}}
        <div class="tarjeta">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="tarjeta-titulo !mb-0">Datos del examen</h2>
                <x-estado :estado="$examen->estado" />
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                <div class="dato"><dt>Código</dt><dd class="font-mono text-sm">{{ $examen->codigo }}</dd></div>
                <div class="dato"><dt>Tipo de muestra</dt><dd>{{ $examen->tipo_muestra }}</dd></div>
                <div class="dato xl:col-span-2"><dt>Indicaciones previas</dt><dd>{{ $examen->indicaciones_previas ?? '—' }}</dd></div>
            </dl>
        </div>

        {{-- CU33 paso 2: parámetros --}}
        <div class="tarjeta space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="tarjeta-titulo !mb-0">Parámetros ({{ $examen->parametros->count() }})</h2>
                <x-primary-button type="button" @click="abrir('parametro', {}, {{ Js::from($parametroVacio) }})">Nuevo parámetro</x-primary-button>
            </div>

            @if ($examen->parametros->isEmpty())
                <p class="alerta-aviso">El examen no tiene parámetros: no se puede solicitar en una orden hasta que tenga al menos uno.</p>
            @endif

            {{-- novalidate: la validación del navegador la hace validarParametro() (más abajo), en español --}}
            <form x-ref="parametro" x-show="parametroAbierto" x-cloak method="POST" novalidate
                  :action="parametro.id ? {{ Js::from(route('parametros-laboratorio.modificar')) }} : {{ Js::from(route('parametros-laboratorio.guardar')) }}"
                  @submit="if (! validarParametro($event.target)) $event.preventDefault()"
                  class="grid grid-cols-1 gap-4 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-4">
                @csrf
                <input type="hidden" name="formulario" value="parametro">
                <input type="hidden" name="tipo_examen_id" value="{{ $examen->id }}">
                <input type="hidden" name="id" :value="parametro.id" :disabled="! parametro.id">
                <input type="hidden" name="con_resultados" :value="parametro.con_resultados ? 1 : 0">
                <h3 class="font-semibold text-marino-900 sm:col-span-4" x-text="parametro.id ? 'Modificar parámetro' : 'Nuevo parámetro'"></h3>
                <p class="alerta-info sm:col-span-4" x-show="parametro.con_resultados">El parámetro ya tiene resultados: no se cambia su tipo de valor ni su unidad.</p>
                <div class="sm:col-span-2">
                    <x-input-label for="parametro_nombre" value="Nombre" />
                    <x-text-input id="parametro_nombre" name="nombre" type="text" maxlength="255" x-model="parametro.nombre" />
                    <p class="js-error" data-campo="nombre" hidden></p>
                    <x-input-error :messages="$errores('parametro', 'nombre')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="parametro_tipo" value="Tipo de valor" />
                    <select id="parametro_tipo" name="tipo_valor" x-model="parametro.tipo_valor" class="campo" :disabled="parametro.con_resultados">
                        @foreach ($tiposValor as $valor => $texto)
                            <option value="{{ $valor }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                    {{-- Con resultados el select va deshabilitado: el valor actual viaja oculto --}}
                    <input type="hidden" name="tipo_valor" :value="parametro.tipo_valor" :disabled="! parametro.con_resultados">
                    <x-input-error :messages="$errores('parametro', 'tipo_valor')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="parametro_orden" value="Orden" />
                    <x-text-input id="parametro_orden" name="orden" type="number" min="1" max="99" step="1" x-model="parametro.orden" />
                    <p class="js-error" data-campo="orden" hidden></p>
                    <x-input-error :messages="$errores('parametro', 'orden')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="parametro_unidad" value="Unidad (obligatoria si es numérico)" />
                    <x-text-input id="parametro_unidad" name="unidad" type="text" maxlength="50" x-model="parametro.unidad" placeholder="g/dL" ::readonly="parametro.con_resultados" />
                    <p class="js-error" data-campo="unidad" hidden></p>
                    <x-input-error :messages="$errores('parametro', 'unidad')" class="mt-2" />
                </div>
                <div class="flex flex-wrap items-end gap-3 sm:col-span-2">
                    <x-primary-button x-text="parametro.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                    <x-secondary-button @click="parametroAbierto = false">Cancelar</x-secondary-button>
                </div>
            </form>

            @foreach ($examen->parametros as $parametro)
                <div class="rounded-lg border border-slate-200 p-4 text-sm">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <span class="font-mono text-xs text-slate-500">#{{ $parametro->orden }}</span>
                        <span class="font-semibold text-marino-900">{{ $parametro->nombre }}</span>
                        <span class="text-slate-600">{{ $tiposValor[$parametro->tipo_valor] }}{{ $parametro->unidad ? ' · ' . $parametro->unidad : '' }}</span>
                        <span class="text-slate-500">{{ $parametro->resultados_count }} resultado(s)</span>
                        <div class="acciones ms-auto">
                            <button type="button" class="accion"
                                    @click="abrir('parametro', {{ Js::from(['id' => $parametro->id, 'nombre' => $parametro->nombre, 'unidad' => $parametro->unidad ?? '', 'tipo_valor' => $parametro->tipo_valor, 'orden' => (string) $parametro->orden, 'con_resultados' => $parametro->resultados_count > 0]) }}, {{ Js::from($parametroVacio) }})">Modificar</button>
                            @if ($parametro->tipo_valor === 'NUMERICO')
                                <button type="button" class="accion"
                                        @click="abrir('rango', {{ Js::from(['parametro_laboratorio_id' => $parametro->id, 'parametro' => $parametro->nombre]) }}, {{ Js::from($rangoVacio) }})">Nuevo rango</button>
                            @endif
                        </div>
                    </div>

                    {{-- CU33 paso 3: rangos del parámetro NUMERICO --}}
                    @if ($parametro->tipo_valor === 'NUMERICO')
                        <div class="tabla-contenedor mt-3">
                            <table class="tabla">
                                <thead>
                                    <tr><th>Edad</th><th>Sexo</th><th>Normal</th><th>Críticos</th><th>Vigencia</th><th>Uso</th><th class="text-right">Acciones</th></tr>
                                </thead>
                                <tbody>
                                    @forelse ($parametro->rangos as $rango)
                                        <tr class="align-top {{ $rango->vigente_hasta && $rango->vigente_hasta->isPast() ? 'text-slate-400' : '' }}">
                                            <td class="whitespace-nowrap">
                                                {{ $rango->edad_min_meses }}–{{ $rango->edad_max_meses }} meses
                                                <span class="block text-xs text-slate-500">{{ \Illuminate\Support\Str::after(\App\Models\RangoReferencia::textoMeses($rango->edad_min_meses), '= ') }} a {{ \Illuminate\Support\Str::after(\App\Models\RangoReferencia::textoMeses($rango->edad_max_meses), '= ') }}</span>
                                            </td>
                                            <td>{{ $sexos[$rango->sexo ?? ''] }}</td>
                                            <td class="whitespace-nowrap font-semibold">{{ $rango->texto() }}</td>
                                            <td class="whitespace-nowrap">{{ $rango->textoCriticos() }}</td>
                                            <td class="whitespace-nowrap">{{ $rango->vigente_desde->format('d/m/Y') }} – {{ $rango->vigente_hasta?->format('d/m/Y') ?? 'sin fin' }}</td>
                                            <td class="whitespace-nowrap">
                                                {{-- CU33 A4: un rango usado no se edita --}}
                                                @if ($rango->resultados_count > 0)
                                                    <span class="text-amber-700">Usado en {{ $rango->resultados_count }} resultados: no se edita</span>
                                                @else
                                                    <span class="text-slate-500">Sin usar</span>
                                                @endif
                                            </td>
                                            <td>
                                                <div class="acciones" x-data="{ cerrar: {{ Js::from($volvio === 'cerrar' && (string) old('id') === (string) $rango->id) }} }">
                                                    @if ($rango->resultados_count === 0)
                                                        <button type="button" class="accion" @click="abrir('rango', {{ Js::from($datosRango($rango, $parametro)) }}, {{ Js::from($rangoVacio) }})">Modificar</button>
                                                    @endif
                                                    <button type="button" class="accion" @click="cerrar = ! cerrar">Cerrar vigencia</button>
                                                    {{-- CU33 A4: cerrar la vigencia (vigente_hasta); luego se registra la versión nueva --}}
                                                    <form x-show="cerrar" x-cloak method="POST" action="{{ route('rangos-referencia.eliminar') }}" class="mt-2 flex flex-wrap items-center gap-2"
                                                          onsubmit="return confirm('¿Cerrar la vigencia del rango en esa fecha?')">
                                                        @csrf
                                                        <input type="hidden" name="formulario" value="cerrar">
                                                        <input type="hidden" name="id" value="{{ $rango->id }}">
                                                        <x-text-input name="vigente_hasta" type="date" :min="$rango->vigente_desde->toDateString()" :max="$rango->vigente_hasta?->toDateString()"
                                                                      :value="$volvio === 'cerrar' && (string) old('id') === (string) $rango->id ? old('vigente_hasta') : today()->toDateString()" class="!w-auto" />
                                                        <x-primary-button>Cerrar</x-primary-button>
                                                        @if ($volvio === 'cerrar' && (string) old('id') === (string) $rango->id)
                                                            <x-input-error :messages="$errors->get('vigente_hasta')" class="w-full" />
                                                        @endif
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="py-4 text-center text-slate-500">Sin rangos de referencia: los resultados se cargarán sin interpretación.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- CU33 paso 3: formulario de rango (nuevo o modificar uno sin usar) --}}
        {{-- novalidate: la validación del navegador la hace validarRango() (más abajo), en español --}}
        <form x-ref="rango" x-show="rangoAbierto" x-cloak method="POST" novalidate
              :action="rango.id ? {{ Js::from(route('rangos-referencia.modificar')) }} : {{ Js::from(route('rangos-referencia.guardar')) }}"
              @submit="if (! validarRango($event.target)) $event.preventDefault()"
              class="tarjeta grid grid-cols-1 gap-4 sm:grid-cols-4">
            @csrf
            <input type="hidden" name="formulario" value="rango">
            <input type="hidden" name="parametro_laboratorio_id" :value="rango.parametro_laboratorio_id">
            <input type="hidden" name="parametro" :value="rango.parametro">
            <input type="hidden" name="id" :value="rango.id" :disabled="! rango.id">
            <h3 class="tarjeta-titulo !mb-0 sm:col-span-4" x-text="(rango.id ? 'Modificar rango de ' : 'Nuevo rango de ') + rango.parametro"></h3>
            @if ($volvio === 'rango' && $errors->has('rango'))
                <div class="alerta-error font-medium sm:col-span-4">{{ $errors->first('rango') }}</div>
            @endif
            <div>
                <x-input-label for="edad_min_meses" value="Edad mínima (meses)" />
                <x-text-input id="edad_min_meses" name="edad_min_meses" type="number" min="0" max="1500" step="1" x-model="rango.edad_min_meses" />
                <p class="ayuda" x-text="textoMeses(rango.edad_min_meses)"></p>
                <p class="js-error" data-campo="edad_min_meses" hidden></p>
                <x-input-error :messages="$errores('rango', 'edad_min_meses')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="edad_max_meses" value="Edad máxima (meses)" />
                <x-text-input id="edad_max_meses" name="edad_max_meses" type="number" min="0" max="1500" step="1" x-model="rango.edad_max_meses" />
                <p class="ayuda" x-text="textoMeses(rango.edad_max_meses)"></p>
                <p class="js-error" data-campo="edad_max_meses" hidden></p>
                <x-input-error :messages="$errores('rango', 'edad_max_meses')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="sexo" value="Sexo" />
                <select id="sexo" name="sexo" x-model="rango.sexo" class="campo">
                    @foreach ($sexos as $valor => $texto)
                        <option value="{{ $valor }}">{{ $texto }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errores('rango', 'sexo')" class="mt-1" />
            </div>
            <div></div>
            @foreach ([['valor_min', 'Valor mínimo'], ['valor_max', 'Valor máximo'], ['critico_min', 'Crítico mínimo (opcional)'], ['critico_max', 'Crítico máximo (opcional)']] as [$campo, $etiqueta])
                <div>
                    <x-input-label :for="$campo" :value="$etiqueta" />
                    <x-text-input :id="$campo" :name="$campo" type="number" step="any" x-model="rango.{{ $campo }}" />
                    <p class="js-error" data-campo="{{ $campo }}" hidden></p>
                    <x-input-error :messages="$errores('rango', $campo)" class="mt-1" />
                </div>
            @endforeach
            <div>
                <x-input-label for="vigente_desde" value="Vigente desde" />
                <x-text-input id="vigente_desde" name="vigente_desde" type="date" x-model="rango.vigente_desde" />
                <p class="js-error" data-campo="vigente_desde" hidden></p>
                <x-input-error :messages="$errores('rango', 'vigente_desde')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="vigente_hasta" value="Vigente hasta (opcional)" />
                <x-text-input id="vigente_hasta" name="vigente_hasta" type="date" x-model="rango.vigente_hasta" />
                <p class="js-error" data-campo="vigente_hasta" hidden></p>
                <x-input-error :messages="$errores('rango', 'vigente_hasta')" class="mt-1" />
            </div>
            <div class="flex flex-wrap items-end gap-3 sm:col-span-2">
                <x-primary-button x-text="rango.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                <x-secondary-button @click="rangoAbierto = false">Cancelar</x-secondary-button>
            </div>
            <p class="text-xs text-slate-500 sm:col-span-4">Un rango no puede cruzarse con otro del mismo parámetro en edad y vigencia si es del mismo sexo, o si uno de los dos es «Ambos».</p>
        </form>
    </div>

    {{-- Validación en el cliente (CU33 pasos 2 y 3). El servidor vuelve a validar lo mismo. --}}
    <script>
        function limpiarErroresJs(contenedor) {
            contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        }

        function mostrarErroresJs(form, errores) {
            limpiarErroresJs(form);
            Object.entries(errores).forEach(function ([campo, mensaje]) {
                const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                if (p) { p.textContent = mensaje; p.hidden = false; }
            });
            return Object.keys(errores).length === 0;
        }

        // CU33 paso 3: «216 meses = 18 años» (como RangoReferencia::textoMeses).
        function textoMeses(valor) {
            if (valor === '' || ! /^\d+$/.test(String(valor))) return '';
            const meses = Number(valor), anios = Math.floor(meses / 12), resto = meses % 12;
            const a = anios + (anios === 1 ? ' año' : ' años'), m = resto + (resto === 1 ? ' mes' : ' meses');
            return meses + ' meses = ' + (anios === 0 ? m : (resto === 0 ? a : a + ' y ' + m));
        }

        function validarParametro(form) {
            const valor = (campo) => form.elements[campo].value.trim();
            const tipo = form.querySelector('[name="tipo_valor"]:not([disabled])').value;
            const errores = {};
            if (valor('nombre') === '') errores.nombre = 'El campo Nombre es obligatorio.';
            if (! /^\d+$/.test(valor('orden')) || Number(valor('orden')) < 1 || Number(valor('orden')) > 99) errores.orden = 'El orden debe estar entre 1 y 99.';
            if (tipo === 'NUMERICO' && valor('unidad') === '' && ! form.elements['id'].value) errores.unidad = 'La unidad es obligatoria en un parámetro numérico.';
            return mostrarErroresJs(form, errores);
        }

        // CU33 paso 3: lo mismo que ck_rangos_edades, ck_rangos_valores y ck_rangos_vigencia.
        function validarRango(form) {
            const valor = (campo) => form.elements[campo].value.trim();
            const num = (campo) => valor(campo) === '' ? null : Number(valor(campo));
            const errores = {};
            const entero = (campo) => /^\d+$/.test(valor(campo)) && Number(valor(campo)) <= 1500;
            if (! entero('edad_min_meses')) errores.edad_min_meses = 'La edad mínima debe ser un número entero de meses (0 a 1500).';
            if (! entero('edad_max_meses')) errores.edad_max_meses = 'La edad máxima debe ser un número entero de meses (0 a 1500).';
            else if (! errores.edad_min_meses && num('edad_max_meses') < num('edad_min_meses')) errores.edad_max_meses = 'La edad máxima no puede ser menor que la mínima.';
            if (num('valor_min') === null && num('valor_max') === null) errores.valor_min = 'Indique el valor mínimo, el máximo o ambos.';
            if (num('valor_min') !== null && num('valor_max') !== null && num('valor_max') < num('valor_min')) errores.valor_max = 'El valor máximo no puede ser menor que el mínimo.';
            if (num('critico_min') !== null && num('valor_min') !== null && num('critico_min') > num('valor_min')) errores.critico_min = 'El crítico mínimo no puede ser mayor que el valor mínimo.';
            if (num('critico_max') !== null && num('valor_max') !== null && num('critico_max') < num('valor_max')) errores.critico_max = 'El crítico máximo no puede ser menor que el valor máximo.';
            if (valor('vigente_desde') === '') errores.vigente_desde = 'Indique desde qué fecha está vigente el rango.';
            if (valor('vigente_hasta') !== '' && valor('vigente_hasta') < valor('vigente_desde')) errores.vigente_hasta = 'La fecha Vigente hasta no puede ser anterior a Vigente desde.';
            return mostrarErroresJs(form, errores);
        }
    </script>
</x-app-layout>
