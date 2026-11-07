{{-- CU30 Consultar cardex (RF-31) paso 3: datos del paciente y de la historia, alergias, antecedentes,
     consultas finalizadas, tratamientos y resultados de laboratorio.
     CU29 Gestionar historia clínica (RF-30): con $puedeEditar (médico con historia.gestionar) los formularios
     están en la misma página; el paciente (A1) solo consulta. Un formulario con errores se reabre con old(). --}}
@php
    $persona = $paciente->persona;
    $documento = $persona->numero_documento
        ? trim((\App\Models\Persona::TIPOS_DOCUMENTO[$persona->tipo_documento] ?? $persona->tipo_documento) . ' ' . $persona->numero_documento
            . ($persona->complemento ? '-' . $persona->complemento : ''))
        : 'Sin documento';

    // CU30 paso 3: alergias activas arriba; las dadas de baja, plegadas.
    $alergiasActivas = $historia->alergias->where('activa', true);
    $alergiasBaja = $historia->alergias->where('activa', false);
    // CU30 paso 3: antecedentes vigentes agrupados por tipo (en el orden de Antecedente::TIPOS); los demás, plegados.
    $vigentes = $historia->antecedentes->where('vigente', true)->groupBy('tipo')
        ->sortBy(fn ($grupo, $tipo) => array_search($tipo, array_keys(\App\Models\Antecedente::TIPOS), true));
    $noVigentes = $historia->antecedentes->where('vigente', false);
    $tratamientos = $consultas->flatMap(fn ($consulta) => $consulta->tratamientos->each(fn ($t) => $t->setRelation('consulta', $consulta)));
    $claseSeveridad = ['LEVE' => 'insignia-inasistencia', 'MODERADA' => 'insignia-inasistencia', 'GRAVE' => 'insignia-cancelada'];
    $claseRespuesta = ['PENDIENTE' => 'insignia-inactivo', 'MEJORO' => 'insignia-activo', 'SIN_CAMBIO' => 'insignia-inasistencia', 'EMPEORO' => 'insignia-cancelada'];

    // CU29: qué formulario volvió con errores (campo oculto «formulario»).
    $volvio = old('formulario');
    $alergiaVacia = ['id' => null, 'tipo' => '', 'sustancia' => '', 'reaccion' => '', 'severidad' => ''];
    $antecedenteVacio = ['id' => null, 'tipo' => '', 'descripcion' => '', 'fecha_inicio' => '', 'vigente' => true, 'dado_de_baja' => false];
    $alergiaInicial = $volvio === 'alergia'
        ? array_merge($alergiaVacia, array_map(fn ($v) => $v ?? '', \Illuminate\Support\Arr::only(old(), ['tipo', 'sustancia', 'reaccion', 'severidad'])), ['id' => old('id')])
        : null;
    $antecedenteInicial = $volvio === 'antecedente'
        ? array_merge($antecedenteVacio, array_map(fn ($v) => $v ?? '', \Illuminate\Support\Arr::only(old(), ['tipo', 'descripcion', 'fecha_inicio'])),
            ['id' => old('id'), 'vigente' => (bool) old('vigente'), 'dado_de_baja' => (bool) old('dado_de_baja')])
        : null;
    $errores = fn ($formulario, $campo) => $volvio === $formulario ? $errors->get($campo) : [];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Atención clínica / Cardex" :titulo="'Cardex · ' . $paciente->codigo . ' · ' . $persona->nombre_completo">
            @if ($acceso === 'medico')
                <a href="{{ route('historias-clinicas.listar') }}" class="font-semibold text-teal-700 hover:underline">Mis pacientes</a>
            @endif
        </x-encabezado>
    </x-slot>

    <div class="space-y-6"
         x-data="{
            historiaAbierta: {{ Js::from($volvio === 'historia') }},
            alergiaAbierta: {{ Js::from($alergiaInicial !== null) }},
            alergia: {{ Js::from($alergiaInicial ?? $alergiaVacia) }},
            antecedenteAbierto: {{ Js::from($antecedenteInicial !== null) }},
            antecedente: {{ Js::from($antecedenteInicial ?? $antecedenteVacio) }},
            abrir(ref, datos, vacio, bandera) {
                this[ref] = Object.fromEntries(Object.entries({ ...vacio, ...(datos ?? {}) }).map(([k, v]) => [k, v ?? '']));
                this[bandera] = true;
                limpiarErroresJs(this.$refs[bandera]);
                this.$nextTick(() => this.$refs[bandera].scrollIntoView({ behavior: 'smooth', block: 'center' }));
            },
         }">
        @if ($errors->hasAny(['operacion', 'id', 'historia_clinica_id']) && ! $volvio)
            <div class="alerta-error font-medium">
                <ul class="space-y-1">
                    @foreach (array_merge($errors->get('operacion'), $errors->get('id'), $errors->get('historia_clinica_id')) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- CU20 A1 / CU30 paso 2: el paciente con menores a cargo cambia de cardex --}}
        @if ($opciones->count() > 1)
            <form method="GET" class="tarjeta max-w-md" onsubmit="return false">
                <x-input-label for="cardex" value="Ver el cardex de" class="!mb-1" />
                <select id="cardex" class="campo" onchange="window.location = this.value">
                    @foreach ($opciones as $opcion)
                        <option value="{{ route('historias-clinicas.mostrar', $opcion->id) }}" @selected($opcion->id === $paciente->id)>
                            {{ $opcion->persona_id === auth()->user()->persona_id ? 'Mi cardex' : $opcion->persona->nombre_completo . ' (menor a mi cargo)' }}
                        </option>
                    @endforeach
                </select>
            </form>
        @endif

        {{-- CU30 paso 3: datos del paciente y de la historia. CU29 paso 1: grupo sanguíneo y observaciones --}}
        <div class="tarjeta space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="tarjeta-titulo !mb-0">Paciente e historia clínica</h2>
                @if ($puedeEditar)
                    <x-secondary-button @click="historiaAbierta = ! historiaAbierta">Modificar historia</x-secondary-button>
                @endif
            </div>
            <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                <div class="dato"><dt>Código</dt><dd>{{ $paciente->codigo }}</dd></div>
                <div class="dato"><dt>Nombre</dt><dd>{{ $persona->nombre_completo }}</dd></div>
                <div class="dato"><dt>Documento</dt><dd>{{ $documento }}</dd></div>
                <div class="dato"><dt>Edad y sexo</dt><dd>{{ $persona->edad }} años · {{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                <div class="dato"><dt>Historia abierta el</dt><dd>{{ $historia->fecha_apertura?->format('d/m/Y') }}</dd></div>
                <div class="dato"><dt>Grupo sanguíneo</dt><dd class="font-semibold">{{ $historia->textoGrupoSanguineo() }}</dd></div>
                <div class="dato xl:col-span-2"><dt>Observaciones</dt><dd class="whitespace-pre-line">{{ $historia->observaciones ?? '—' }}</dd></div>
            </dl>

            @if ($puedeEditar)
                <form x-ref="historiaAbierta" x-show="historiaAbierta" x-cloak method="POST" action="{{ route('historias-clinicas.modificar') }}"
                      class="grid grid-cols-1 gap-4 border-t border-slate-200 pt-4 sm:grid-cols-3">
                    @csrf
                    <input type="hidden" name="formulario" value="historia">
                    <input type="hidden" name="id" value="{{ $historia->id }}">
                    <div>
                        <x-input-label for="grupo_sanguineo" value="Grupo sanguíneo" />
                        <select id="grupo_sanguineo" name="grupo_sanguineo" class="campo">
                            @foreach ($grupos as $valor => $texto)
                                <option value="{{ $valor }}" @selected(($volvio === 'historia' ? old('grupo_sanguineo') : $historia->grupo_sanguineo) === $valor)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errores('historia', 'grupo_sanguineo')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="observaciones" value="Observaciones (opcional)" />
                        <textarea id="observaciones" name="observaciones" rows="2" maxlength="2000" class="campo">{{ $volvio === 'historia' ? old('observaciones') : $historia->observaciones }}</textarea>
                        <x-input-error :messages="$errores('historia', 'observaciones')" class="mt-2" />
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-3">
                        <x-primary-button>Guardar historia</x-primary-button>
                        <x-secondary-button @click="historiaAbierta = false">Cancelar</x-secondary-button>
                    </div>
                </form>
            @endif
        </div>

        {{-- CU30 paso 3: alergias ACTIVAS destacadas. CU29 paso 3: registrar, modificar y dar de baja --}}
        <div class="tarjeta space-y-4 {{ $alergiasActivas->isNotEmpty() ? 'border-red-300 ring-1 ring-red-200' : '' }}">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="tarjeta-titulo !mb-0">Alergias</h2>
                @if ($puedeEditar)
                    <x-primary-button type="button" @click="abrir('alergia', null, {{ Js::from($alergiaVacia) }}, 'alergiaAbierta')">Registrar alergia</x-primary-button>
                @endif
            </div>

            @forelse ($alergiasActivas as $alergia)
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border border-red-200 bg-red-50 px-4 py-2.5 text-sm">
                    <span class="font-semibold text-red-800">{{ $alergia->sustancia }}</span>
                    <span class="text-slate-700">{{ $alergia->textoTipo() }}</span>
                    <span class="insignia {{ $claseSeveridad[$alergia->severidad] ?? 'insignia-inactivo' }}">{{ $alergia->textoSeveridad() }}</span>
                    <span class="text-slate-600">Reacción: {{ $alergia->reaccion ?? '—' }}</span>
                    @if ($puedeEditar)
                        <div class="acciones ms-auto">
                            <button type="button" class="accion"
                                    @click="abrir('alergia', {{ Js::from($alergia->only(['id', 'tipo', 'sustancia', 'reaccion', 'severidad'])) }}, {{ Js::from($alergiaVacia) }}, 'alergiaAbierta')">Modificar</button>
                            <form method="POST" action="{{ route('alergias.eliminar') }}"
                                  onsubmit="return confirm({{ Js::from('¿Dar de baja la alergia a ' . $alergia->sustancia . '?') }});">
                                @csrf
                                <input type="hidden" name="id" value="{{ $alergia->id }}">
                                <button type="submit" class="accion-peligro">Dar de baja</button>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Sin alergias activas registradas.</p>
            @endforelse

            @if ($alergiasBaja->isNotEmpty())
                <details class="text-sm">
                    <summary class="cursor-pointer font-semibold text-slate-600">Alergias dadas de baja ({{ $alergiasBaja->count() }})</summary>
                    <ul class="mt-2 divide-y divide-slate-100">
                        @foreach ($alergiasBaja as $alergia)
                            <li class="py-1.5 text-slate-500">{{ $alergia->sustancia }} · {{ $alergia->textoTipo() }} · {{ $alergia->textoSeveridad() }} · {{ $alergia->reaccion ?? '—' }}</li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($puedeEditar)
                {{-- novalidate: la validación del navegador la hace validarAlergia() (más abajo), en español --}}
                <form x-ref="alergiaAbierta" x-show="alergiaAbierta" x-cloak method="POST" novalidate
                      :action="alergia.id ? {{ Js::from(route('alergias.modificar')) }} : {{ Js::from(route('alergias.guardar')) }}"
                      @submit="if (! validarAlergia($event.target)) $event.preventDefault()"
                      class="grid grid-cols-1 gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="formulario" value="alergia">
                    <input type="hidden" name="historia_clinica_id" value="{{ $historia->id }}">
                    <input type="hidden" name="id" :value="alergia.id" :disabled="! alergia.id">
                    <h3 class="font-semibold text-marino-900 sm:col-span-2" x-text="alergia.id ? 'Modificar alergia' : 'Registrar alergia'"></h3>
                    <div>
                        <x-input-label for="alergia_tipo" value="Tipo" />
                        <select id="alergia_tipo" name="tipo" x-model="alergia.tipo" class="campo">
                            <option value="">Seleccione…</option>
                            @foreach ($tiposAlergia as $valor => $texto)
                                <option value="{{ $valor }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                        <p class="js-error" data-campo="tipo" hidden></p>
                        <x-input-error :messages="$errores('alergia', 'tipo')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="alergia_sustancia" value="Sustancia" />
                        <x-text-input id="alergia_sustancia" name="sustancia" type="text" maxlength="255" x-model="alergia.sustancia" placeholder="Por ejemplo, Penicilina" />
                        <p class="js-error" data-campo="sustancia" hidden></p>
                        <x-input-error :messages="$errores('alergia', 'sustancia')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="alergia_reaccion" value="Reacción (opcional)" />
                        <x-text-input id="alergia_reaccion" name="reaccion" type="text" maxlength="255" x-model="alergia.reaccion" />
                        <x-input-error :messages="$errores('alergia', 'reaccion')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="alergia_severidad" value="Severidad" />
                        <select id="alergia_severidad" name="severidad" x-model="alergia.severidad" class="campo">
                            <option value="">Seleccione…</option>
                            @foreach ($severidades as $valor => $texto)
                                <option value="{{ $valor }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                        <p class="js-error" data-campo="severidad" hidden></p>
                        <x-input-error :messages="$errores('alergia', 'severidad')" class="mt-2" />
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                        <x-primary-button x-text="alergia.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                        <x-secondary-button @click="alergiaAbierta = false">Cancelar</x-secondary-button>
                    </div>
                </form>
            @endif
        </div>

        {{-- CU30 paso 3: antecedentes vigentes por tipo (MEDICACION_ACTUAL = «Medicación habitual»). CU29 paso 2 --}}
        <div class="tarjeta space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="tarjeta-titulo !mb-0">Antecedentes y medicación habitual</h2>
                @if ($puedeEditar)
                    <x-primary-button type="button" @click="abrir('antecedente', null, {{ Js::from($antecedenteVacio) }}, 'antecedenteAbierto')">Registrar antecedente</x-primary-button>
                @endif
            </div>

            @forelse ($vigentes as $tipo => $grupo)
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ \App\Models\Antecedente::TIPOS[$tipo] ?? $tipo }}</h3>
                    <ul class="mt-1 divide-y divide-slate-100 text-sm">
                        @foreach ($grupo as $antecedente)
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-1 py-2">
                                <span class="text-marino-900">{{ $antecedente->descripcion }}</span>
                                @if ($antecedente->fecha_inicio)
                                    <span class="text-slate-500">desde {{ $antecedente->fecha_inicio->format('d/m/Y') }}</span>
                                @endif
                                @if ($puedeEditar)
                                    <div class="acciones ms-auto">
                                        <button type="button" class="accion"
                                                @click="abrir('antecedente', {{ Js::from(['id' => $antecedente->id, 'tipo' => $antecedente->tipo, 'descripcion' => $antecedente->descripcion, 'fecha_inicio' => $antecedente->fecha_inicio?->toDateString(), 'vigente' => true, 'dado_de_baja' => false]) }}, {{ Js::from($antecedenteVacio) }}, 'antecedenteAbierto')">Modificar</button>
                                        <form method="POST" action="{{ route('antecedentes.eliminar') }}"
                                              onsubmit="return confirm({{ Js::from('¿Dar de baja el antecedente «' . $antecedente->descripcion . '»?') }});">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $antecedente->id }}">
                                            <button type="submit" class="accion-peligro">Dar de baja</button>
                                        </form>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @empty
                <p class="text-sm text-slate-500">Sin antecedentes vigentes registrados.</p>
            @endforelse

            @if ($noVigentes->isNotEmpty())
                <details class="text-sm">
                    <summary class="cursor-pointer font-semibold text-slate-600">Antecedentes no vigentes ({{ $noVigentes->count() }})</summary>
                    <ul class="mt-2 divide-y divide-slate-100">
                        @foreach ($noVigentes as $antecedente)
                            <li class="flex flex-wrap items-center gap-x-4 py-1.5 text-slate-500">
                                <span>{{ $antecedente->textoTipo() }}: {{ $antecedente->descripcion }}</span>
                                @if ($puedeEditar)
                                    {{-- CU29 paso 2: reactivar con update (vigente = true) --}}
                                    <button type="button" class="accion ms-auto"
                                            @click="abrir('antecedente', {{ Js::from(['id' => $antecedente->id, 'tipo' => $antecedente->tipo, 'descripcion' => $antecedente->descripcion, 'fecha_inicio' => $antecedente->fecha_inicio?->toDateString(), 'vigente' => true, 'dado_de_baja' => true]) }}, {{ Js::from($antecedenteVacio) }}, 'antecedenteAbierto')">Reactivar</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($puedeEditar)
                {{-- novalidate: la validación del navegador la hace validarAntecedente() (más abajo), en español --}}
                <form x-ref="antecedenteAbierto" x-show="antecedenteAbierto" x-cloak method="POST" novalidate
                      :action="antecedente.id ? {{ Js::from(route('antecedentes.modificar')) }} : {{ Js::from(route('antecedentes.guardar')) }}"
                      @submit="if (! validarAntecedente($event.target)) $event.preventDefault()"
                      class="grid grid-cols-1 gap-4 border-t border-slate-200 pt-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="formulario" value="antecedente">
                    <input type="hidden" name="historia_clinica_id" value="{{ $historia->id }}">
                    <input type="hidden" name="id" :value="antecedente.id" :disabled="! antecedente.id">
                    <input type="hidden" name="dado_de_baja" :value="antecedente.dado_de_baja ? 1 : 0">
                    <h3 class="font-semibold text-marino-900 sm:col-span-2" x-text="antecedente.id ? (antecedente.dado_de_baja ? 'Reactivar antecedente' : 'Modificar antecedente') : 'Registrar antecedente'"></h3>
                    <div>
                        <x-input-label for="antecedente_tipo" value="Tipo" />
                        <select id="antecedente_tipo" name="tipo" x-model="antecedente.tipo" class="campo">
                            <option value="">Seleccione…</option>
                            @foreach ($tiposAntecedente as $valor => $texto)
                                <option value="{{ $valor }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                        <p class="js-error" data-campo="tipo" hidden></p>
                        <x-input-error :messages="$errores('antecedente', 'tipo')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="antecedente_fecha" value="Desde (opcional)" />
                        <x-text-input id="antecedente_fecha" name="fecha_inicio" type="date" max="{{ today()->toDateString() }}" x-model="antecedente.fecha_inicio" />
                        <p class="js-error" data-campo="fecha_inicio" hidden></p>
                        <x-input-error :messages="$errores('antecedente', 'fecha_inicio')" class="mt-2" />
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="antecedente_descripcion" value="Descripción" />
                        <textarea id="antecedente_descripcion" name="descripcion" rows="2" maxlength="2000" x-model="antecedente.descripcion" class="campo"
                                  placeholder="Por ejemplo, Metformina 850 mg cada 12 horas"></textarea>
                        <p class="js-error" data-campo="descripcion" hidden></p>
                        <x-input-error :messages="$errores('antecedente', 'descripcion')" class="mt-2" />
                    </div>
                    {{-- CU29 paso 2: al reactivar, vuelve a quedar vigente --}}
                    <input type="hidden" name="vigente" value="1" :disabled="! antecedente.dado_de_baja">
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                        <x-primary-button x-text="antecedente.id ? (antecedente.dado_de_baja ? 'Reactivar' : 'Guardar cambios') : 'Registrar'">Guardar</x-primary-button>
                        <x-secondary-button @click="antecedenteAbierto = false">Cancelar</x-secondary-button>
                    </div>
                </form>
            @endif
        </div>

        {{-- CU30 paso 3: consultas FINALIZADAS, la más reciente primero --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Consultas ({{ $consultas->count() }})</h2>
            @forelse ($consultas as $consulta)
                @php $asignacion = $consulta->cita->ficha->horario->medicoEspecialidad; @endphp
                <div class="rounded-lg border border-slate-200 p-4 text-sm">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-semibold text-marino-900">
                            {{ $consulta->inicio->format('d/m/Y') }} · {{ \App\Services\CitaService::tituloMedico($asignacion->medico->persona) }} · {{ $asignacion->especialidad->nombre }}
                        </p>
                        <span class="text-slate-500">Cita {{ $consulta->cita->codigo }}</span>
                    </div>
                    <dl class="datos-compactos mt-2 grid grid-cols-1 gap-x-8 xl:grid-cols-2">
                        <div class="dato"><dt>Signos vitales</dt><dd>{{ $consulta->textoSignosVitales() }}</dd></div>
                        <div class="dato"><dt>Diagnóstico</dt><dd><span class="font-semibold">{{ $consulta->codigo_cie10 ?? '—' }}</span> {{ $consulta->diagnostico }}</dd></div>
                        <div class="dato xl:col-span-2"><dt>Indicaciones</dt><dd class="whitespace-pre-line">{{ $consulta->indicaciones ?? '—' }}</dd></div>
                    </dl>
                </div>
            @empty
                <p class="text-sm text-slate-500">El paciente aún no tiene consultas finalizadas.</p>
            @endforelse
        </div>

        {{-- CU30 paso 3: tratamientos de esas consultas con sus prescripciones --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Tratamientos ({{ $tratamientos->count() }})</h2>
            @forelse ($tratamientos as $tratamiento)
                <div class="rounded-lg border border-slate-200 p-4 text-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-marino-900">{{ $tratamiento->descripcion }}</p>
                        <span class="insignia insignia-inactivo">{{ \App\Models\Tratamiento::ESTADOS[$tratamiento->estado] ?? $tratamiento->estado }}</span>
                        <span class="insignia {{ $claseRespuesta[$tratamiento->respuesta] ?? 'insignia-inactivo' }}">Respuesta: {{ \App\Models\Tratamiento::RESPUESTAS[$tratamiento->respuesta] ?? $tratamiento->respuesta }}</span>
                    </div>
                    <p class="mt-1 text-slate-500">
                        Desde {{ $tratamiento->fecha_inicio->format('d/m/Y') }}{{ $tratamiento->fecha_fin ? ' hasta ' . $tratamiento->fecha_fin->format('d/m/Y') : '' }}
                        · consulta del {{ $tratamiento->consulta->inicio->format('d/m/Y') }}
                    </p>
                    @if ($tratamiento->observacion_evolucion)
                        <p class="mt-1 text-slate-600">{{ $tratamiento->observacion_evolucion }}</p>
                    @endif
                    @if ($tratamiento->prescripciones->isNotEmpty())
                        <div class="tabla-contenedor mt-3">
                            <table class="tabla">
                                <thead><tr><th>Medicamento</th><th>Dosis</th><th>Frecuencia</th><th>Duración</th><th>Vía</th></tr></thead>
                                <tbody>
                                    @foreach ($tratamiento->prescripciones as $prescripcion)
                                        <tr>
                                            <td class="font-semibold">{{ $prescripcion->medicamento }}</td>
                                            <td>{{ $prescripcion->dosis }}</td>
                                            <td>{{ $prescripcion->frecuencia }}</td>
                                            <td class="whitespace-nowrap">{{ $prescripcion->duracion_dias }} días</td>
                                            <td>{{ $prescripcion->via }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Sin tratamientos registrados.</p>
            @endforelse
        </div>

        {{-- CU30 paso 3: resultados de laboratorio (solo tabla; el gráfico de evolución es CU36) --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Resultados de laboratorio ({{ $resultados->count() }})</h2>
            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Examen</th>
                            <th>Parámetro</th>
                            <th>Valor</th>
                            <th>Rango aplicado</th>
                            <th>Interpretación</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($resultados as $resultado)
                            <tr>
                                <td class="whitespace-nowrap">{{ $resultado->fecha_resultado->format('d/m/Y') }}</td>
                                <td>{{ $resultado->orden->tipoExamen->nombre }}</td>
                                <td>{{ $resultado->parametro->nombre }}</td>
                                <td class="whitespace-nowrap font-semibold">{{ $resultado->textoValor() }}</td>
                                <td class="whitespace-nowrap">{{ $resultado->rango?->texto() ?? '—' }}</td>
                                <td><span class="insignia {{ $resultado->claseInterpretacion() }}">{{ $resultado->textoInterpretacion() }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-8 text-center text-slate-500">Sin resultados de laboratorio.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($puedeEditar)
        {{-- Validación en el cliente (CU29 pasos 2 y 3). El servidor vuelve a validar lo mismo. --}}
        <script>
            // Quita los mensajes de una validación anterior.
            function limpiarErroresJs(contenedor) {
                contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
            }

            function mostrarErroresJs(form, errores) {
                limpiarErroresJs(form);
                Object.entries(errores).forEach(function ([campo, mensaje]) {
                    const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                    p.textContent = mensaje;
                    p.hidden = false;
                });
                return Object.keys(errores).length === 0;
            }

            function validarAlergia(form) {
                const valor = (campo) => form.elements[campo].value.trim();
                const errores = {};
                if (valor('tipo') === '') errores.tipo = 'Elija el tipo de alergia.';
                if (valor('sustancia') === '') errores.sustancia = 'El campo Sustancia es obligatorio.';
                if (valor('severidad') === '') errores.severidad = 'Elija la severidad.';
                return mostrarErroresJs(form, errores);
            }

            function validarAntecedente(form) {
                const valor = (campo) => form.elements[campo].value.trim();
                const errores = {};
                if (valor('tipo') === '') errores.tipo = 'Elija el tipo de antecedente.';
                if (valor('descripcion') === '') errores.descripcion = 'El campo Descripción es obligatorio.';
                // Fecha local AAAA-MM-DD de hoy: el inicio no puede ser futuro.
                const hoy = new Date();
                const textoHoy = hoy.getFullYear() + '-' + String(hoy.getMonth() + 1).padStart(2, '0') + '-' + String(hoy.getDate()).padStart(2, '0');
                if (valor('fecha_inicio') !== '' && valor('fecha_inicio') > textoHoy) errores.fecha_inicio = 'La fecha de inicio no puede ser futura.';
                return mostrarErroresJs(form, errores);
            }
        </script>
    @endif
</x-app-layout>
