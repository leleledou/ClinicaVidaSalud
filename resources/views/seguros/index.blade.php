{{-- CU24 Gestionar seguros del paciente (RF-18): datos del paciente, lista de pólizas y formulario en la misma página. --}}
@php
    $persona = $paciente->persona;
    $camposFormulario = ['aseguradora_id', 'numero_poliza', 'plan', 'titular', 'vigente_desde', 'vigente_hasta'];
    // Si el servidor devolvió errores del formulario, se vuelve a abrir con lo escrito.
    $volvioFormulario = $errors->hasAny($camposFormulario);

    // CU24 paso 3: el titular propuesto es el propio paciente.
    $vacio = array_merge(['id' => null], array_fill_keys($camposFormulario, ''), ['titular' => $persona->nombre_completo]);
    $inicial = $volvioFormulario
        ? array_merge($vacio, array_map(fn ($valor) => $valor ?? '', \Illuminate\Support\Arr::only(old(), $camposFormulario)), ['id' => old('id')])
        : null;

    // Datos de una póliza para el botón Modificar (las fechas salen como AAAA-MM-DD).
    $datosEditar = fn ($seguro) => [
        'id' => $seguro->id,
        'aseguradora_id' => (string) $seguro->aseguradora_id,
        'numero_poliza' => $seguro->numero_poliza,
        'plan' => $seguro->plan ?? '',
        'titular' => $seguro->titular,
        'vigente_desde' => $seguro->vigente_desde->toDateString(),
        'vigente_hasta' => $seguro->vigente_hasta->toDateString(),
    ];

    $documento = $persona->numero_documento
        ? $persona->numero_documento . ($persona->complemento ? '-' . $persona->complemento : '')
        : 'Sin documento';
    $claseSituacion = ['Vigente' => 'insignia-activo', 'Vencida' => 'insignia-cancelada', 'Aún no vigente' => 'insignia-programada'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Pacientes / Seguros" titulo="Seguros del paciente" />
    </x-slot>

    <div class="space-y-6"
         x-data="{
            abierto: {{ Js::from($volvioFormulario) }},
            vacio: {{ Js::from($vacio) }},
            form: {},
            init() { this.form = {{ Js::from($inicial) }} ?? { ...this.vacio }; },
            nuevo() { this.form = { ...this.vacio }; this.mostrar(); },
            editar(seguro) { this.form = { ...seguro }; this.mostrar(); },
            mostrar() {
                this.abierto = true;
                limpiarErroresJs(this.$refs.formulario);
                this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
            },
         }">
        <div class="space-y-6">
            @if ($errors->hasAny(['operacion', 'id', 'paciente_id']) && ! $volvioFormulario)
                <div class="alerta-error font-medium">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id'), $errors->get('paciente_id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- CU24 paso 1: datos del paciente --}}
            <div class="tarjeta">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="tarjeta-titulo !mb-0">Paciente</h2>
                    @can('pacientes.buscar')
                        <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="btn-secundario">Volver a la ficha</a>
                    @endcan
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">Código</dt><dd class="mt-1 font-medium text-marino-900">{{ $paciente->codigo }}</dd></div>
                    <div><dt class="text-slate-500">Paciente</dt><dd class="mt-1 font-semibold text-marino-900">{{ $persona->nombre_completo }}</dd></div>
                    <div><dt class="text-slate-500">Documento</dt><dd class="mt-1 font-medium text-marino-900">{{ $documento }}</dd></div>
                </dl>
            </div>

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- CU24 paso 2: lista de pólizas --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Pólizas de seguro</h2>
                        @if ($puedeGestionar)
                            <x-primary-button type="button" @click="nuevo()">Registrar póliza</x-primary-button>
                        @endif
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Aseguradora</th>
                                    <th>Número</th>
                                    <th>Plan</th>
                                    <th>Titular</th>
                                    <th>Vigencia</th>
                                    <th>Verificación</th>
                                    <th>Estado</th>
                                    @if ($puedeGestionar || $puedeVerificar)
                                        <th class="text-right">Acciones</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($seguros as $seguro)
                                    @php $situacion = $seguro->situacion(); @endphp
                                    <tr class="align-top">
                                        <td>
                                            <span class="font-semibold text-marino-900">{{ $seguro->aseguradora->nombre }}</span>
                                            @if ($seguro->aseguradora->estado !== 'ACTIVO')
                                                <span class="block text-xs text-red-700">Aseguradora inactiva (RN20)</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap">{{ $seguro->numero_poliza }}</td>
                                        <td>{{ $seguro->plan ?? '—' }}</td>
                                        <td>{{ $seguro->titular }}</td>
                                        <td class="whitespace-nowrap">
                                            {{ $seguro->vigente_desde->format('d/m/Y') }} a {{ $seguro->vigente_hasta->format('d/m/Y') }}
                                            <span class="insignia {{ $claseSituacion[$situacion] }} mt-1">{{ $situacion }}</span>
                                        </td>
                                        <td>
                                            @if ($seguro->verificada())
                                                <span class="text-green-700">Verificada por {{ $seguro->recepcionista?->persona->nombre_completo }} el {{ $seguro->fecha_verificacion->format('d/m/Y') }}</span>
                                            @else
                                                <span class="text-amber-700">Pendiente</span>
                                            @endif
                                        </td>
                                        <td><x-estado :estado="$seguro->estado" /></td>
                                        @if ($puedeGestionar || $puedeVerificar)
                                            <td>
                                                <div class="acciones">
                                                    @if ($seguro->estado === 'ACTIVO')
                                                        {{-- CU24 A1 --}}
                                                        @if ($puedeGestionar)
                                                            <button type="button" class="accion" @click="editar({{ Js::from($datosEditar($seguro)) }})">Modificar</button>
                                                        @endif

                                                        {{-- CU24 A2: verificar; el id viaja oculto --}}
                                                        @if ($puedeVerificar && ! $seguro->verificada())
                                                            <form method="POST" action="{{ route('seguros-paciente.verificar') }}"
                                                                  onsubmit="return confirm({{ Js::from('¿Confirma que revisó la póliza '.$seguro->numero_poliza.' con la aseguradora?') }});">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $seguro->id }}">
                                                                <button type="submit" class="accion">Verificar</button>
                                                            </form>
                                                        @endif

                                                        {{-- CU24 A3: desactivar con confirmación; el id viaja oculto --}}
                                                        @if ($puedeGestionar)
                                                            <form method="POST" action="{{ route('seguros-paciente.eliminar') }}"
                                                                  onsubmit="return confirm({{ Js::from('¿Desactivar la póliza '.$seguro->numero_poliza.' de '.$seguro->aseguradora->nombre.'? Ya no se podrá usar en citas nuevas.') }});">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $seguro->id }}">
                                                                <button type="submit" class="accion-peligro">Desactivar</button>
                                                            </form>
                                                        @endif
                                                    @elseif ($puedeGestionar)
                                                        {{-- CU24 A4 --}}
                                                        <form method="POST" action="{{ route('seguros-paciente.activar') }}">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $seguro->id }}">
                                                            <button type="submit" class="accion">Activar</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-8 text-center text-slate-500">
                                            <p>El paciente aún no tiene pólizas registradas</p>
                                            @if ($puedeGestionar)
                                                <x-primary-button type="button" class="mt-3" @click="nuevo()">Registrar póliza</x-primary-button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- CU24 pasos 3 a 5 / A1: formulario de registro y modificación --}}
            @if ($puedeGestionar)
                <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                    <section class="space-y-6">
                        <header class="space-y-3">
                            <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar póliza' : 'Registrar póliza'"></h2>
                            @if ($puedeVerificar)
                                <p class="alerta-info">Al guardar, la póliza queda verificada a su nombre: revise antes la póliza con la aseguradora.</p>
                            @else
                                <p class="alerta-aviso">La póliza quedará pendiente hasta que una recepcionista la verifique.</p>
                            @endif
                            <p class="text-sm text-slate-600" x-show="form.id">Si cambia la aseguradora, el número, el titular o la vigencia, la verificación se vuelve a registrar.</p>
                        </header>

                        {{-- novalidate: la validación del navegador la hace validarSeguro() (más abajo), en español --}}
                        <form method="POST" novalidate class="space-y-6"
                              :action="form.id ? {{ Js::from(route('seguros-paciente.modificar')) }} : {{ Js::from(route('seguros-paciente.guardar')) }}"
                              @submit="if (! validarSeguro($event.target)) $event.preventDefault()">
                            @csrf
                            <input type="hidden" name="paciente_id" value="{{ $paciente->id }}">
                            {{-- CU24 A1: el id viaja oculto solo al modificar --}}
                            <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                            <div>
                                <x-input-label for="aseguradora_id" value="Aseguradora" />
                                <select id="aseguradora_id" name="aseguradora_id" x-model="form.aseguradora_id" class="campo">
                                    <option value="">Seleccione…</option>
                                    @foreach ($aseguradoras as $aseguradora)
                                        <option value="{{ $aseguradora->id }}">{{ $aseguradora->nombre }}</option>
                                    @endforeach
                                </select>
                                <p class="js-error" data-campo="aseguradora_id" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('aseguradora_id')" class="mt-2" /> @endif
                            </div>

                            <div>
                                <x-input-label for="numero_poliza" value="Número de póliza" />
                                <x-text-input id="numero_poliza" name="numero_poliza" type="text" class="mt-1 block w-full" x-model="form.numero_poliza" maxlength="255" />
                                <p class="js-error" data-campo="numero_poliza" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('numero_poliza')" class="mt-2" /> @endif
                            </div>

                            <div>
                                <x-input-label for="plan" value="Plan (opcional)" />
                                <x-text-input id="plan" name="plan" type="text" class="mt-1 block w-full" x-model="form.plan" maxlength="255" />
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('plan')" class="mt-2" /> @endif
                            </div>

                            <div>
                                <x-input-label for="titular" value="Titular" />
                                <x-text-input id="titular" name="titular" type="text" class="mt-1 block w-full" x-model="form.titular" maxlength="255" />
                                <p class="mt-1 text-xs text-slate-500">Quien contrató la póliza (por ejemplo, el padre o la madre en un seguro familiar).</p>
                                <p class="js-error" data-campo="titular" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('titular')" class="mt-2" /> @endif
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="vigente_desde" value="Vigente desde" />
                                    <x-text-input id="vigente_desde" name="vigente_desde" type="date" class="mt-1 block w-full" x-model="form.vigente_desde" />
                                    <p class="js-error" data-campo="vigente_desde" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('vigente_desde')" class="mt-2" /> @endif
                                </div>
                                <div>
                                    <x-input-label for="vigente_hasta" value="Vigente hasta" />
                                    <x-text-input id="vigente_hasta" name="vigente_hasta" type="date" class="mt-1 block w-full" x-model="form.vigente_hasta" />
                                    <p class="js-error" data-campo="vigente_hasta" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('vigente_hasta')" class="mt-2" /> @endif
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                                <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                            </div>
                        </form>
                    </section>
                </div>
            @endif
            </div>
        </div>
    </div>

    {{-- Validación en el cliente (CU24 paso 4). El servidor vuelve a validar lo mismo. --}}
    <script>
        // Quita los mensajes de una validación anterior.
        function limpiarErroresJs(contenedor) {
            contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        }

        function validarSeguro(form) {
            limpiarErroresJs(form);
            const valor = (campo) => form.elements[campo].value.trim();
            const fecha = /^\d{4}-\d{2}-\d{2}$/;
            const errores = {};

            if (valor('aseguradora_id') === '') {
                errores.aseguradora_id = 'Elija la aseguradora.';
            }
            if (valor('numero_poliza') === '') {
                errores.numero_poliza = 'El campo Número de póliza es obligatorio.';
            }
            if (valor('titular') === '') {
                errores.titular = 'El campo Titular es obligatorio.';
            }
            if (valor('vigente_desde') === '') {
                errores.vigente_desde = 'El campo Vigente desde es obligatorio.';
            } else if (! fecha.test(valor('vigente_desde'))) {
                errores.vigente_desde = 'La fecha Vigente desde no es válida.';
            }
            if (valor('vigente_hasta') === '') {
                errores.vigente_hasta = 'El campo Vigente hasta es obligatorio.';
            } else if (! fecha.test(valor('vigente_hasta'))) {
                errores.vigente_hasta = 'La fecha Vigente hasta no es válida.';
            } else if (! errores.vigente_desde && valor('vigente_hasta') < valor('vigente_desde')) {
                // ck_seguros_vigencia
                errores.vigente_hasta = 'La fecha Vigente hasta no puede ser anterior a Vigente desde.';
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
