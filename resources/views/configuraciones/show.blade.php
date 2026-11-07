{{-- CU22 paso 3 Gestionar configuración del sistema (RF-07): modificar el valor de un parámetro.
     La clave, el tipo de dato y la descripción se muestran pero no se editan. --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Seguridad / Configuración del sistema" :titulo="'Parámetro: ' . $configuracion->clave">
            <a href="{{ route('configuraciones.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        @if ($errors->has('clave'))
            <div class="alerta-error font-medium">{{ $errors->first('clave') }}</div>
        @endif

        <div class="tarjeta max-w-2xl">
            <h2 class="tarjeta-titulo">Modificar parámetro</h2>

            <dl class="grid grid-cols-1 gap-x-10 sm:grid-cols-2">
                <div class="dato">
                    <dt>Clave</dt>
                    <dd class="font-mono text-sm">{{ $configuracion->clave }}</dd>
                </div>
                <div class="dato">
                    <dt>Tipo de dato</dt>
                    <dd>{{ $configuracion->tipo_dato }}</dd>
                </div>
                <div class="dato sm:col-span-2">
                    <dt>Descripción</dt>
                    <dd>{{ $configuracion->descripcion ?? '—' }}</dd>
                </div>
            </dl>

            {{-- novalidate: la validación del navegador la hace validarConfiguracion() (más abajo), en español --}}
            <form method="POST" action="{{ route('configuraciones.modificar') }}" novalidate
                  onsubmit="return validarConfiguracion(this)"
                  class="mt-6 space-y-6">
                @csrf
                {{-- CU22 paso 4: la clave viaja oculta --}}
                <input type="hidden" name="clave" value="{{ $configuracion->clave }}">

                <div>
                    <x-input-label for="valor" value="Valor" />
                    {{-- CU22 paso 5: el campo depende del tipo de dato --}}
                    @switch ($configuracion->tipo_dato)
                        @case ('int')
                            <x-text-input id="valor" name="valor" type="number" step="1" :min="$minimo" class="mt-1 block w-full" :value="old('valor', $configuracion->valor)" />
                            <p class="mt-1 text-xs text-slate-500">Número entero, mínimo {{ $minimo }}.</p>
                            @break
                        @case ('bool')
                            <select id="valor" name="valor" class="campo">
                                <option value="true" @selected(old('valor', $configuracion->valor) === 'true')>true (sí)</option>
                                <option value="false" @selected(old('valor', $configuracion->valor) === 'false')>false (no)</option>
                            </select>
                            @break
                        @case ('decimal')
                            <x-text-input id="valor" name="valor" type="number" step="any" class="mt-1 block w-full" :value="old('valor', $configuracion->valor)" />
                            @break
                        @default
                            <x-text-input id="valor" name="valor" type="text" maxlength="255" class="mt-1 block w-full" :value="old('valor', $configuracion->valor)" />
                    @endswitch
                    <p class="js-error" data-campo="valor" hidden></p>
                    <x-input-error :messages="$errors->get('valor')" class="mt-2" />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <x-primary-button>Guardar cambios</x-primary-button>
                    <a href="{{ route('configuraciones.listar') }}" class="btn-secundario">Cancelar</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Validación en el cliente (CU22 paso 5). El servidor vuelve a validar lo mismo. --}}
    <script>
        function validarConfiguracion(form) {
            const tipo = {{ Js::from($configuracion->tipo_dato) }};
            const minimo = {{ Js::from($minimo) }};
            const valor = form.elements['valor'].value.trim();
            const p = form.querySelector('.js-error[data-campo="valor"]');
            let error = '';

            if (valor === '') {
                error = 'El campo Valor es obligatorio.';
            } else if (tipo === 'int' && ! /^[+-]?\d+$/.test(valor)) {
                error = 'El valor debe ser un número entero.';
            } else if (tipo === 'int' && Number(valor) < minimo) {
                error = 'El valor debe ser como mínimo ' + minimo + '.';
            } else if (tipo === 'bool' && valor !== 'true' && valor !== 'false') {
                error = 'El valor debe ser true o false.';
            } else if (tipo === 'decimal' && ! isFinite(Number(valor))) {
                error = 'El valor debe ser un número.';
            } else if (tipo === 'string' && valor.length > 255) {
                error = 'El valor no puede superar los 255 caracteres.';
            }

            p.textContent = error;
            p.hidden = error === '';
            return error === '';
        }
    </script>
</x-app-layout>
