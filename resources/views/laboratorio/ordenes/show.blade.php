{{-- Ficha de la orden de laboratorio. CU35 Registrar resultados de laboratorio (RF-36) paso 2: datos de la orden y,
     si está SOLICITADA o MUESTRA_TOMADA y el usuario es la Recepcionista (el médico solo revisa), el formulario de toma de
     muestra y valores. CU36 Revisar resultados y evolución (RF-37) pasos 2 a 4: tabla de resultados y evolución de
     cada parámetro NUMERICO (gráfico SVG sin librerías). El formulario con errores se reabre con old(). --}}
@php
    $persona = $paciente->persona;
    $examen = $orden->tipoExamen;
    $medicoPersona = $medico?->persona;
    // CU35 paso 2: para MUESTRA_TOMADA, la fecha de toma y el laboratorio vienen llenos; si no, ahora.
    $tomaInicial = old('fecha_toma_muestra', ($orden->fecha_toma_muestra ?? now())->format('Y-m-d\TH:i'));
    $laboratorioInicial = old('laboratorio_procesador', $orden->laboratorio_procesador);
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Laboratorio / Orden" :titulo="'Orden ' . $orden->codigo . ' · ' . $persona->nombre_completo">
            @if ($paciente->accesoClinico(auth()->user()))
                <a href="{{ route('historias-clinicas.mostrar', $paciente->id) }}" class="font-semibold text-teal-700 hover:underline">Ver cardex</a>
            @endif
            <a href="{{ route('ordenes-laboratorio.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        {{-- CU35 A2: la orden ya no espera resultados (otro usuario la cargó o se anuló) --}}
        @if ($errors->has('operacion'))
            <div class="alerta-error font-medium">{{ $errors->first('operacion') }}</div>
        @endif

        {{-- CU35 paso 2: paciente, examen, médico solicitante y prioridad --}}
        <div class="tarjeta">
            <div class="flex flex-wrap items-center gap-3">
                <h2 class="tarjeta-titulo !mb-0">Orden y paciente</h2>
                <span class="insignia {{ $orden->claseEstado() }}">{{ $orden->textoEstado() }}</span>
                @if ($orden->prioridad === 'URGENTE')
                    <span class="insignia insignia-cancelada">Urgente</span>
                @endif
            </div>
            <dl class="mt-4 grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                <div class="dato"><dt>Paciente</dt><dd>{{ $persona->nombre_completo }} ({{ $paciente->codigo }})</dd></div>
                <div class="dato"><dt>Edad y sexo</dt><dd>{{ $persona->edad }} años · {{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                <div class="dato"><dt>Examen</dt><dd>{{ $examen->nombre }}</dd></div>
                <div class="dato"><dt>Tipo de muestra</dt><dd>{{ $examen->tipo_muestra }}</dd></div>
                <div class="dato xl:col-span-2"><dt>Indicaciones previas</dt><dd>{{ $examen->indicaciones_previas ?? '—' }}</dd></div>
                <div class="dato"><dt>Médico solicitante</dt><dd>{{ $medicoPersona ? \App\Services\CitaService::tituloMedico($medicoPersona) : '—' }}</dd></div>
                <div class="dato"><dt>Prioridad</dt><dd>{{ $prioridades[$orden->prioridad] ?? $orden->prioridad }}</dd></div>
                <div class="dato"><dt>Solicitada</dt><dd>{{ $orden->fecha_solicitud->format('d/m/Y H:i') }}</dd></div>
                <div class="dato"><dt>Toma de muestra</dt><dd>{{ $orden->fecha_toma_muestra?->format('d/m/Y H:i') ?? '—' }}</dd></div>
                <div class="dato"><dt>Laboratorio que procesó</dt><dd>{{ $orden->laboratorio_procesador ?? '—' }}</dd></div>
                <div class="dato"><dt>Revisada por el médico</dt><dd>{{ $orden->fecha_revision?->format('d/m/Y H:i') ?? '—' }}</dd></div>
            </dl>
        </div>

        {{-- CU35 pasos 2 y 3: toma de muestra y valores (A1: solo si está pendiente y es la Recepcionista) --}}
        @if ($puedeCargar)
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo !mb-0">Registrar resultados</h2>
                {{-- novalidate: la validación del navegador la hace validarResultados() (más abajo), en español --}}
                <form method="POST" action="{{ route('resultados-laboratorio.guardar') }}" novalidate onsubmit="return validarResultados(this)" class="space-y-4">
                    @csrf
                    <input type="hidden" name="orden_laboratorio_id" value="{{ $orden->id }}">

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="fecha_toma_muestra" value="Fecha y hora de la toma de muestra" />
                            <x-text-input id="fecha_toma_muestra" name="fecha_toma_muestra" type="datetime-local" :value="$tomaInicial"
                                          :min="$orden->fecha_solicitud->format('Y-m-d\TH:i')" :max="now()->format('Y-m-d\TH:i')" />
                            <p class="js-error" data-campo="fecha_toma_muestra" hidden></p>
                            <x-input-error :messages="$errors->get('fecha_toma_muestra')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="laboratorio_procesador" value="Laboratorio que procesó (opcional)" />
                            <x-text-input id="laboratorio_procesador" name="laboratorio_procesador" type="text" maxlength="255" :value="$laboratorioInicial" />
                            <x-input-error :messages="$errors->get('laboratorio_procesador')" class="mt-2" />
                        </div>
                    </div>

                    {{-- Un campo por parámetro, en su orden; la referencia es el rango que se aplicará hoy (RN23) --}}
                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead><tr><th>Parámetro</th><th>Valor</th><th>Referencia</th><th>Observación (opcional)</th></tr></thead>
                            <tbody>
                                @foreach ($examen->parametros as $parametro)
                                    @php
                                        $numerico = $parametro->tipo_valor === 'NUMERICO';
                                        $referencia = $numerico ? \App\Models\RangoReferencia::aplicable($parametro->id, $persona->fecha_nacimiento, $persona->sexo, now()) : null;
                                    @endphp
                                    <tr class="align-top">
                                        <td class="font-semibold">{{ $parametro->nombre }}</td>
                                        <td>
                                            <div class="flex items-center gap-2">
                                                <input type="text" name="valores[{{ $parametro->id }}]" value="{{ old('valores.' . $parametro->id) }}"
                                                       class="campo {{ $numerico ? '!w-32' : '' }}" @if ($numerico) inputmode="decimal" @endif
                                                       data-parametro="{{ $parametro->nombre }}" data-numerico="{{ $numerico ? '1' : '0' }}"
                                                       aria-label="Valor de {{ $parametro->nombre }}">
                                                @if ($numerico && $parametro->unidad)
                                                    <span class="whitespace-nowrap text-sm text-slate-500">{{ $parametro->unidad }}</span>
                                                @endif
                                            </div>
                                            <p class="js-error" data-campo="valores[{{ $parametro->id }}]" hidden></p>
                                            <x-input-error :messages="$errors->get('valores.' . $parametro->id)" class="mt-2" />
                                        </td>
                                        <td class="whitespace-nowrap text-sm text-slate-600">
                                            @if (! $numerico)
                                                Texto
                                            @elseif ($referencia)
                                                {{ $referencia->texto() }}
                                            @else
                                                Sin rango aplicable
                                            @endif
                                        </td>
                                        <td>
                                            <input type="text" name="observaciones[{{ $parametro->id }}]" value="{{ old('observaciones.' . $parametro->id) }}" maxlength="500"
                                                   class="campo" aria-label="Observación de {{ $parametro->nombre }}">
                                            <x-input-error :messages="$errors->get('observaciones.' . $parametro->id)" class="mt-2" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <x-primary-button>Registrar resultados</x-primary-button>
                </form>
            </div>
        @endif

        {{-- CU36 paso 2: resultados de la orden (A3: todavía sin resultados) --}}
        @if ($orden->resultados->isNotEmpty())
            <div class="tarjeta space-y-4">
                <h2 class="tarjeta-titulo !mb-0">Resultados</h2>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr><th>Parámetro</th><th>Valor</th><th>Rango aplicado</th><th>Interpretación</th><th>Observación</th><th>Cargado por</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($orden->resultados as $resultado)
                                <tr class="align-top">
                                    <td>{{ $resultado->parametro->nombre }}</td>
                                    <td class="whitespace-nowrap font-semibold">{{ $resultado->textoValor() }}</td>
                                    <td class="whitespace-nowrap">{{ $resultado->rango?->texto() ?? '—' }}</td>
                                    <td><span class="insignia {{ $resultado->claseInterpretacion() }}">{{ $resultado->textoInterpretacion() }}</span></td>
                                    <td>{{ $resultado->observacion ?? '—' }}</td>
                                    <td class="text-sm">
                                        {{ $resultado->usuarioCarga?->persona?->nombre_completo ?? $resultado->usuarioCarga?->nombre_usuario ?? '—' }}
                                        <span class="block text-slate-500">{{ $resultado->fecha_resultado->format('d/m/Y H:i') }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @elseif (! $puedeCargar)
            <p class="alerta-info">La orden todavía no tiene resultados.</p>
        @endif

        {{-- CU36 paso 4: evolución de cada parámetro NUMERICO con todos los resultados del paciente --}}
        @if ($evoluciones->isNotEmpty())
            <div class="tarjeta space-y-6">
                <h2 class="tarjeta-titulo !mb-0">Evolución</h2>
                @foreach ($evoluciones as $evolucion)
                    @include('laboratorio.ordenes.grafico-evolucion', [
                        'parametro' => $evolucion['parametro'],
                        'resultados' => $evolucion['resultados'],
                        'ordenActual' => $orden->id,
                    ])
                @endforeach
            </div>
        @endif
    </div>

    @if ($puedeCargar)
        {{-- Validación en el cliente (CU35 paso 5). El servidor vuelve a validar lo mismo. --}}
        <script>
            function validarResultados(form) {
                form.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
                const errores = {};

                const toma = form.elements['fecha_toma_muestra'];
                if (toma.value === '') errores['fecha_toma_muestra'] = 'Indique la fecha y hora de la toma de muestra.';
                else if (toma.value < toma.min) errores['fecha_toma_muestra'] = 'La toma de muestra no puede ser anterior a la solicitud ({{ $orden->fecha_solicitud->format('d/m/Y H:i') }}).';
                else if (new Date(toma.value) > new Date()) errores['fecha_toma_muestra'] = 'La toma de muestra no puede estar en el futuro.';

                form.querySelectorAll('input[data-parametro]').forEach(function (campo) {
                    const valor = campo.value.trim();
                    const nombre = campo.dataset.parametro;
                    if (valor === '') errores[campo.name] = 'Indique el valor de ' + nombre + '.';
                    else if (campo.dataset.numerico === '1' && ! /^-?\d{1,8}([.,]\d{1,4})?$/.test(valor)) {
                        errores[campo.name] = 'El valor de ' + nombre + ' debe ser un número (hasta 4 decimales).';
                    }
                });

                Object.entries(errores).forEach(function ([campo, mensaje]) {
                    const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                    p.textContent = mensaje;
                    p.hidden = false;
                });
                return Object.keys(errores).length === 0;
            }
        </script>
    @endif
</x-app-layout>
