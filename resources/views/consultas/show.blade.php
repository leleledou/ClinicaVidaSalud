{{-- CU31 Atender consulta médica (RF-32) paso 4: la cita y el paciente, sus alergias activas destacadas, la
     medicación habitual y los antecedentes vigentes, y el formulario clínico (guardar avance / finalizar).
     FINALIZADA: todo en solo lectura. CU32 (RF-33): tratamientos y prescripciones en la misma página (solo EN_CURSO)
     y «Registrar evolución» en cada tratamiento. Los formularios con errores se reabren con old(). --}}
@php
    $cita = $consulta->cita;
    $persona = $paciente->persona;
    $asignacion = $cita->ficha->horario->medicoEspecialidad;
    $historia = $paciente->historiaClinica;
    $alergias = $historia?->alergias ?? collect();
    $antecedentes = $historia?->antecedentes ?? collect();
    $medicacion = $antecedentes->where('tipo', 'MEDICACION_ACTUAL');
    $otrosAntecedentes = $antecedentes->where('tipo', '!=', 'MEDICACION_ACTUAL');
    // CU31 paso 5: lo escrito si el servidor devolvió errores del formulario clínico; si no, lo guardado.
    $volvioClinico = old('formulario') === 'consulta';
    $valor = fn ($campo) => $volvioClinico ? old($campo) : $consulta->{$campo};
    $claseRespuesta = ['PENDIENTE' => 'insignia-inactivo', 'MEJORO' => 'insignia-activo', 'SIN_CAMBIO' => 'insignia-inasistencia', 'EMPEORO' => 'insignia-cancelada'];
    $signos = [
        ['peso_kg', 'Peso (kg)', '0.5', '400', '0.1'], ['talla_cm', 'Talla (cm)', '30', '250', '0.1'],
        ['presion_sistolica', 'Presión sistólica (mmHg)', '50', '260', '1'], ['presion_diastolica', 'Presión diastólica (mmHg)', '30', '160', '1'],
        ['temperatura_c', 'Temperatura (°C)', '30', '45', '0.1'], ['frecuencia_cardiaca', 'Frecuencia cardiaca (lpm)', '20', '250', '1'],
        ['saturacion_o2', 'Saturación O₂ (%)', '50', '100', '1'],
    ];
    // CU32 paso 1: el formulario de tratamiento se reabre con sus errores.
    $volvioTratamiento = old('formulario') === 'tratamiento';
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Atención clínica / Consulta" :titulo="'Consulta · ' . $cita->codigo . ' · ' . $persona->nombre_completo">
            {{-- CU31 paso 4: enlace al cardex (CU30) --}}
            @if ($paciente->accesoClinico(auth()->user()))
                <a href="{{ route('historias-clinicas.mostrar', $paciente->id) }}" class="font-semibold text-teal-700 hover:underline">Ver cardex</a>
            @endif
            <a href="{{ route('citas.mostrar', $cita->id) }}" class="font-semibold text-teal-700 hover:underline">Ver cita</a>
            <a href="{{ route('consultas.listar') }}" class="font-semibold text-teal-700 hover:underline">Mis consultas</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        {{-- Reglas de negocio que impidieron guardar --}}
        @if ($errors->hasAny(['operacion', 'id']))
            <div class="alerta-error font-medium">{{ $errors->first('operacion') ?: $errors->first('id') }}</div>
        @endif

        {{-- CU31 paso 4: alergias ACTIVAS destacadas arriba --}}
        @if ($alergias->isNotEmpty())
            <div class="rounded-xl border-2 border-red-300 bg-red-50 p-4 text-sm text-red-900">
                <p class="font-bold">Alergias activas</p>
                <ul class="mt-1 space-y-0.5">
                    @foreach ($alergias as $alergia)
                        <li><span class="font-semibold">{{ $alergia->sustancia }}</span> · {{ $alergia->textoTipo() }} · {{ $alergia->textoSeveridad() }}{{ $alergia->reaccion ? ' · ' . $alergia->reaccion : '' }}</li>
                    @endforeach
                </ul>
            </div>
        @else
            <p class="alerta-info">Sin alergias activas registradas.</p>
        @endif

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- CU31 paso 4: datos de la cita y del paciente --}}
            <div class="tarjeta lg:col-span-2">
                <div class="flex flex-wrap items-center gap-3">
                    <h2 class="tarjeta-titulo !mb-0">Cita y paciente</h2>
                    <span class="insignia {{ $enCurso ? 'insignia-programada' : 'insignia-activo' }}">{{ $enCurso ? 'Consulta en curso' : 'Consulta finalizada' }}</span>
                    <x-estado-cita :estado="$cita->estado" />
                </div>
                <dl class="mt-4 grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Paciente</dt><dd>{{ $persona->nombre_completo }} ({{ $paciente->codigo }})</dd></div>
                    <div class="dato"><dt>Edad y sexo</dt><dd>{{ $persona->edad }} años · {{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                    <div class="dato"><dt>Cita</dt><dd>{{ $cita->codigo }} · {{ \Illuminate\Support\Carbon::parse($cita->ficha->fecha)->format('d/m/Y') }} {{ substr($cita->ficha->hora_inicio, 0, 5) }}–{{ substr($cita->ficha->hora_fin, 0, 5) }}</dd></div>
                    <div class="dato"><dt>Especialidad</dt><dd>{{ $asignacion->especialidad->nombre }}</dd></div>
                    <div class="dato"><dt>Motivo de la cita</dt><dd>{{ $cita->motivo ?? '—' }}</dd></div>
                    <div class="dato"><dt>Inicio / fin</dt><dd>{{ $consulta->inicio->format('d/m/Y H:i') }} · {{ $consulta->fin?->format('d/m/Y H:i') ?? 'en curso' }}</dd></div>
                </dl>
            </div>

            {{-- CU31 paso 4: medicación habitual y antecedentes vigentes en resumen --}}
            <div class="tarjeta space-y-3 text-sm">
                <h2 class="tarjeta-titulo !mb-0">Medicación habitual</h2>
                @forelse ($medicacion as $antecedente)
                    <p>{{ $antecedente->descripcion }}</p>
                @empty
                    <p class="text-slate-500">Sin medicación habitual registrada.</p>
                @endforelse
                <h2 class="tarjeta-titulo !mb-0 pt-2">Antecedentes vigentes</h2>
                @forelse ($otrosAntecedentes as $antecedente)
                    <p><span class="font-semibold">{{ $antecedente->textoTipo() }}:</span> {{ $antecedente->descripcion }}</p>
                @empty
                    <p class="text-slate-500">Sin antecedentes vigentes.</p>
                @endforelse
            </div>
        </div>

        {{-- CU31 pasos 5 y 6: formulario clínico (EN_CURSO) o solo lectura (FINALIZADA) --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Registro de la consulta</h2>
            @if ($enCurso)
                {{-- novalidate: la validación del navegador la hace validarConsulta() (más abajo), en español --}}
                <form method="POST" action="{{ route('consultas.modificar') }}" novalidate
                      onsubmit="return validarConsulta(this, event.submitter && event.submitter.value === 'finalizar')"
                      class="space-y-4">
                    @csrf
                    <input type="hidden" name="formulario" value="consulta">
                    <input type="hidden" name="id" value="{{ $consulta->id }}">

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                        <div>
                            <x-input-label for="anamnesis" value="Anamnesis" />
                            <textarea id="anamnesis" name="anamnesis" rows="4" maxlength="5000" class="campo">{{ $valor('anamnesis') }}</textarea>
                            <x-input-error :messages="$errors->get('anamnesis')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="examen_fisico" value="Examen físico" />
                            <textarea id="examen_fisico" name="examen_fisico" rows="4" maxlength="5000" class="campo">{{ $valor('examen_fisico') }}</textarea>
                            <x-input-error :messages="$errors->get('examen_fisico')" class="mt-2" />
                        </div>
                    </div>

                    {{-- CU31 paso 5: signos vitales con sus rangos --}}
                    <fieldset class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-7">
                        <legend class="mb-2 text-sm font-semibold text-marino-900">Signos vitales</legend>
                        @foreach ($signos as [$campo, $etiqueta, $min, $max, $paso])
                            <div>
                                <x-input-label :for="$campo" :value="$etiqueta" />
                                <x-text-input :id="$campo" :name="$campo" type="number" :min="$min" :max="$max" :step="$paso" :value="$valor($campo)" />
                                <p class="js-error" data-campo="{{ $campo }}" hidden></p>
                                <x-input-error :messages="$errors->get($campo)" class="mt-1" />
                            </div>
                        @endforeach
                    </fieldset>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
                        <div>
                            <x-input-label for="codigo_cie10" value="Código CIE-10" />
                            <x-text-input id="codigo_cie10" name="codigo_cie10" type="text" maxlength="10" placeholder="D50.9" :value="$valor('codigo_cie10')" class="uppercase" />
                            <p class="js-error" data-campo="codigo_cie10" hidden></p>
                            <x-input-error :messages="$errors->get('codigo_cie10')" class="mt-2" />
                        </div>
                        <div class="lg:col-span-3">
                            <x-input-label for="diagnostico" value="Diagnóstico" />
                            <x-text-input id="diagnostico" name="diagnostico" type="text" maxlength="2000" :value="$valor('diagnostico')" />
                            <p class="js-error" data-campo="diagnostico" hidden></p>
                            <x-input-error :messages="$errors->get('diagnostico')" class="mt-2" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="indicaciones" value="Indicaciones" />
                        <textarea id="indicaciones" name="indicaciones" rows="3" maxlength="5000" class="campo">{{ $valor('indicaciones') }}</textarea>
                        <x-input-error :messages="$errors->get('indicaciones')" class="mt-2" />
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        {{-- CU31 paso 5: guardar avance y salir --}}
                        <x-secondary-button type="submit" name="accion" value="guardar">Guardar avance</x-secondary-button>
                        {{-- CU31 paso 6: finalizar (exige CIE-10 y diagnóstico; la cita queda ATENDIDA) --}}
                        <x-primary-button name="accion" value="finalizar" formaction="{{ route('consultas.finalizar') }}">Finalizar consulta</x-primary-button>
                        <span class="text-xs text-slate-500">Al finalizar, la consulta ya no se puede modificar y la cita queda atendida.</span>
                    </div>
                </form>
            @else
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato"><dt>Anamnesis</dt><dd class="whitespace-pre-line">{{ $consulta->anamnesis ?? '—' }}</dd></div>
                    <div class="dato"><dt>Examen físico</dt><dd class="whitespace-pre-line">{{ $consulta->examen_fisico ?? '—' }}</dd></div>
                    <div class="dato xl:col-span-2"><dt>Signos vitales</dt><dd>{{ $consulta->textoSignosVitales() }}</dd></div>
                    <div class="dato xl:col-span-2"><dt>Diagnóstico</dt><dd><span class="font-semibold">{{ $consulta->codigo_cie10 }}</span> {{ $consulta->diagnostico }}</dd></div>
                    <div class="dato xl:col-span-2"><dt>Indicaciones</dt><dd class="whitespace-pre-line">{{ $consulta->indicaciones ?? '—' }}</dd></div>
                </dl>
            @endif
        </div>

        {{-- CU32: tratamientos de la consulta con sus prescripciones --}}
        <div class="tarjeta space-y-4" x-data="{ nuevoTratamiento: {{ Js::from($volvioTratamiento) }} }">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="tarjeta-titulo !mb-0">Tratamientos y prescripciones</h2>
                @if ($puedeTratar)
                    <x-primary-button type="button" @click="nuevoTratamiento = ! nuevoTratamiento">Registrar tratamiento</x-primary-button>
                @endif
            </div>

            {{-- CU32 paso 1: registrar tratamiento (solo EN_CURSO) --}}
            @if ($puedeTratar)
                <form x-show="nuevoTratamiento" x-cloak method="POST" action="{{ route('tratamientos.guardar') }}" novalidate
                      onsubmit="return validarTratamiento(this)"
                      class="grid grid-cols-1 gap-4 rounded-lg border border-slate-200 p-4 sm:grid-cols-2">
                    @csrf
                    <input type="hidden" name="formulario" value="tratamiento">
                    <input type="hidden" name="consulta_id" value="{{ $consulta->id }}">
                    @if ($errors->has('tratamiento'))
                        <div class="alerta-error font-medium sm:col-span-2">{{ $errors->first('tratamiento') }}</div>
                    @endif
                    <div class="sm:col-span-2">
                        <x-input-label for="descripcion" value="Descripción" />
                        <textarea id="descripcion" name="descripcion" rows="2" maxlength="2000" class="campo"
                                  placeholder="Por ejemplo, Ferroterapia oral por anemia ferropénica">{{ $volvioTratamiento ? old('descripcion') : '' }}</textarea>
                        <p class="js-error" data-campo="descripcion" hidden></p>
                        @if ($volvioTratamiento) <x-input-error :messages="$errors->get('descripcion')" class="mt-2" /> @endif
                    </div>
                    <div>
                        <x-input-label for="fecha_inicio" value="Fecha de inicio" />
                        <x-text-input id="fecha_inicio" name="fecha_inicio" type="date" :min="$consulta->inicio->toDateString()"
                                      :value="$volvioTratamiento ? old('fecha_inicio') : today()->toDateString()" />
                        <p class="js-error" data-campo="fecha_inicio" hidden></p>
                        @if ($volvioTratamiento) <x-input-error :messages="$errors->get('fecha_inicio')" class="mt-2" /> @endif
                    </div>
                    <div>
                        <x-input-label for="fecha_fin_nuevo" value="Fecha de fin (opcional)" />
                        <x-text-input id="fecha_fin_nuevo" name="fecha_fin" type="date" :value="$volvioTratamiento ? old('fecha_fin') : ''" />
                        <p class="js-error" data-campo="fecha_fin" hidden></p>
                        @if ($volvioTratamiento) <x-input-error :messages="$errors->get('fecha_fin')" class="mt-2" /> @endif
                    </div>
                    {{-- CU32 paso 1: resultado de laboratorio del MISMO paciente que lo origina (opcional) --}}
                    <div class="sm:col-span-2">
                        <x-input-label for="resultado_laboratorio_id" value="Resultado de laboratorio que lo origina (opcional)" />
                        <select id="resultado_laboratorio_id" name="resultado_laboratorio_id" class="campo">
                            <option value="">Ninguno</option>
                            @foreach ($resultados as $resultado)
                                <option value="{{ $resultado->id }}" @selected($volvioTratamiento && (string) old('resultado_laboratorio_id') === (string) $resultado->id)>
                                    {{ $resultado->fecha_resultado->format('d/m/Y') }} · {{ $resultado->parametro->nombre }} {{ $resultado->textoValor() }} · {{ $resultado->textoInterpretacion() }}
                                </option>
                            @endforeach
                        </select>
                        @if ($volvioTratamiento) <x-input-error :messages="$errors->get('resultado_laboratorio_id')" class="mt-2" /> @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
                        <x-primary-button>Registrar tratamiento</x-primary-button>
                        <x-secondary-button @click="nuevoTratamiento = false">Cancelar</x-secondary-button>
                    </div>
                </form>
            @endif

            @forelse ($consulta->tratamientos as $tratamiento)
                @php
                    // CU32 pasos 2 y 3: el formulario de prescripción de este tratamiento se reabre con sus errores o el aviso de alergia.
                    $volvioPrescripcion = (string) old('tratamiento_id') === (string) $tratamiento->id;
                    $avisoAlergia = $volvioPrescripcion ? $errors->first('alergia') : null;
                @endphp
                <div class="rounded-lg border border-slate-200 p-4 text-sm" x-data="{ prescribir: {{ Js::from($volvioPrescripcion) }} }">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-semibold text-marino-900">{{ $tratamiento->descripcion }}</p>
                        <span class="insignia insignia-inactivo">{{ \App\Models\Tratamiento::ESTADOS[$tratamiento->estado] ?? $tratamiento->estado }}</span>
                        <span class="insignia {{ $claseRespuesta[$tratamiento->respuesta] ?? 'insignia-inactivo' }}">Respuesta: {{ \App\Models\Tratamiento::RESPUESTAS[$tratamiento->respuesta] ?? $tratamiento->respuesta }}</span>
                    </div>
                    <p class="mt-1 text-slate-500">
                        Desde {{ $tratamiento->fecha_inicio->format('d/m/Y') }}{{ $tratamiento->fecha_fin ? ' hasta ' . $tratamiento->fecha_fin->format('d/m/Y') : '' }}
                        @if ($tratamiento->resultadoLaboratorio)
                            · originado por {{ $tratamiento->resultadoLaboratorio->parametro->nombre }} {{ $tratamiento->resultadoLaboratorio->textoValor() }}
                        @endif
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

                    <div class="mt-2 flex flex-wrap items-center gap-4">
                        @if ($puedeTratar)
                            <button type="button" class="accion" @click="prescribir = ! prescribir">Agregar prescripción</button>
                        @endif
                    </div>

                    {{-- CU32 pasos 2 y 3: prescripción con advertencia de alergias (se advierte, no se bloquea) --}}
                    @if ($puedeTratar)
                        <form x-show="prescribir" x-cloak method="POST" action="{{ route('prescripciones.guardar') }}" novalidate
                              onsubmit="return validarPrescripcion(this)"
                              class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2 lg:grid-cols-5">
                            @csrf
                            <input type="hidden" name="tratamiento_id" value="{{ $tratamiento->id }}">
                            @if ($volvioPrescripcion && $errors->has('prescripcion'))
                                <div class="alerta-error font-medium sm:col-span-2 lg:col-span-5">{{ $errors->first('prescripcion') }}</div>
                            @endif
                            @if ($avisoAlergia)
                                <div class="space-y-2 rounded-lg border-2 border-red-300 bg-red-50 p-3 text-red-900 sm:col-span-2 lg:col-span-5">
                                    <p class="font-bold">{{ $avisoAlergia }}</p>
                                    <label class="flex items-center gap-2 font-medium">
                                        <input type="checkbox" name="confirmar_alergia" value="1" class="casilla">
                                        Confirmo que revisé la alergia y mantengo la prescripción
                                    </label>
                                </div>
                            @endif
                            <div>
                                <x-input-label :for="'medicamento_' . $tratamiento->id" value="Medicamento" />
                                <x-text-input :id="'medicamento_' . $tratamiento->id" name="medicamento" type="text" maxlength="255" :value="$volvioPrescripcion ? old('medicamento') : ''" />
                                <p class="js-error" data-campo="medicamento" hidden></p>
                                @if ($volvioPrescripcion) <x-input-error :messages="$errors->get('medicamento')" class="mt-1" /> @endif
                            </div>
                            <div>
                                <x-input-label :for="'dosis_' . $tratamiento->id" value="Dosis" />
                                <x-text-input :id="'dosis_' . $tratamiento->id" name="dosis" type="text" maxlength="255" placeholder="300 mg" :value="$volvioPrescripcion ? old('dosis') : ''" />
                                <p class="js-error" data-campo="dosis" hidden></p>
                                @if ($volvioPrescripcion) <x-input-error :messages="$errors->get('dosis')" class="mt-1" /> @endif
                            </div>
                            <div>
                                <x-input-label :for="'frecuencia_' . $tratamiento->id" value="Frecuencia" />
                                <x-text-input :id="'frecuencia_' . $tratamiento->id" name="frecuencia" type="text" maxlength="255" placeholder="cada 24 horas" :value="$volvioPrescripcion ? old('frecuencia') : ''" />
                                <p class="js-error" data-campo="frecuencia" hidden></p>
                                @if ($volvioPrescripcion) <x-input-error :messages="$errors->get('frecuencia')" class="mt-1" /> @endif
                            </div>
                            <div>
                                <x-input-label :for="'duracion_' . $tratamiento->id" value="Duración (días)" />
                                <x-text-input :id="'duracion_' . $tratamiento->id" name="duracion_dias" type="number" min="1" max="365" step="1" :value="$volvioPrescripcion ? old('duracion_dias') : ''" />
                                <p class="js-error" data-campo="duracion_dias" hidden></p>
                                @if ($volvioPrescripcion) <x-input-error :messages="$errors->get('duracion_dias')" class="mt-1" /> @endif
                            </div>
                            <div>
                                <x-input-label :for="'via_' . $tratamiento->id" value="Vía" />
                                <select id="via_{{ $tratamiento->id }}" name="via" class="campo">
                                    <option value="">Seleccione…</option>
                                    @foreach ($vias as $via)
                                        <option value="{{ $via }}" @selected($volvioPrescripcion && old('via') === $via)>{{ $via }}</option>
                                    @endforeach
                                </select>
                                <p class="js-error" data-campo="via" hidden></p>
                                @if ($volvioPrescripcion) <x-input-error :messages="$errors->get('via')" class="mt-1" /> @endif
                            </div>
                            <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-5">
                                <x-primary-button>Agregar prescripción</x-primary-button>
                                <x-secondary-button @click="prescribir = false">Cancelar</x-secondary-button>
                            </div>
                            {{-- CU32 paso 3: la comparación con las alergias es por texto --}}
                            <p class="text-xs text-slate-500 sm:col-span-2 lg:col-span-5">La comparación es por texto: revise siempre la lista de alergias.</p>
                        </form>
                    @endif

                    {{-- CU32 paso 4: registrar evolución (también con la consulta finalizada) --}}
                    @if ($puedeEvolucion)
                        @include('consultas._evolucion', ['tratamiento' => $tratamiento])
                    @endif
                </div>
            @empty
                <p class="text-sm text-slate-500">Esta consulta no tiene tratamientos registrados.</p>
            @endforelse
        </div>
    </div>

    {{-- Validación en el cliente (CU31 paso 5 y CU32 pasos 1-2). El servidor vuelve a validar lo mismo. --}}
    <script>
        function mostrarErroresConsulta(form, errores) {
            form.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
            Object.entries(errores).forEach(function ([campo, mensaje]) {
                const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                if (p) { p.textContent = mensaje; p.hidden = false; }
            });
            return Object.keys(errores).length === 0;
        }

        // CU31 paso 5: rangos de los signos vitales y formato CIE-10; al finalizar, CIE-10 y diagnóstico obligatorios.
        function validarConsulta(form, finalizar) {
            const valor = (campo) => form.elements[campo].value.trim();
            const rangos = {
                peso_kg: [0.5, 400, 'El peso debe estar entre 0,5 y 400 kg.'],
                talla_cm: [30, 250, 'La talla debe estar entre 30 y 250 cm.'],
                presion_sistolica: [50, 260, 'La presión sistólica debe estar entre 50 y 260 mmHg.'],
                presion_diastolica: [30, 160, 'La presión diastólica debe estar entre 30 y 160 mmHg.'],
                temperatura_c: [30, 45, 'La temperatura debe estar entre 30 y 45 °C.'],
                frecuencia_cardiaca: [20, 250, 'La frecuencia cardiaca debe estar entre 20 y 250 lpm.'],
                saturacion_o2: [50, 100, 'La saturación debe estar entre 50 y 100 %.'],
            };
            const errores = {};
            Object.entries(rangos).forEach(function ([campo, [min, max, mensaje]]) {
                const numero = Number(valor(campo));
                if (valor(campo) !== '' && (isNaN(numero) || numero < min || numero > max)) errores[campo] = mensaje;
            });
            if (! errores.presion_sistolica && ! errores.presion_diastolica && (valor('presion_sistolica') === '') !== (valor('presion_diastolica') === '')) {
                errores.presion_sistolica = 'Indique la presión sistólica y la diastólica.';
            } else if (! errores.presion_sistolica && valor('presion_sistolica') !== '' && Number(valor('presion_sistolica')) < Number(valor('presion_diastolica'))) {
                errores.presion_sistolica = 'La presión sistólica no puede ser menor que la diastólica.';
            }
            const cie10 = valor('codigo_cie10').toUpperCase();
            if (cie10 !== '' && ! /^[A-Z][0-9]{2}(\.[A-Z0-9]{1,4})?$/.test(cie10)) {
                errores.codigo_cie10 = 'El código CIE-10 debe tener una letra, dos dígitos y, opcionalmente, un punto y hasta 4 caracteres (por ejemplo, D50.9).';
            }
            if (finalizar) {
                if (cie10 === '') errores.codigo_cie10 = 'Para finalizar registre el código CIE-10.';
                if (valor('diagnostico') === '') errores.diagnostico = 'Para finalizar registre el diagnóstico.';
                if (Object.keys(errores).length === 0 && ! confirm('¿Finalizar la consulta? Ya no se podrá modificar y la cita quedará atendida.')) return false;
            }
            return mostrarErroresConsulta(form, errores);
        }

        // CU32 paso 1: descripción y fecha de inicio obligatorias; fin no anterior al inicio.
        function validarTratamiento(form) {
            const valor = (campo) => form.elements[campo].value.trim();
            const errores = {};
            if (valor('descripcion') === '') errores.descripcion = 'El campo Descripción es obligatorio.';
            if (valor('fecha_inicio') === '') {
                errores.fecha_inicio = 'El campo Fecha de inicio es obligatorio.';
            } else if (valor('fecha_inicio') < form.elements['fecha_inicio'].min) {
                errores.fecha_inicio = 'La fecha de inicio no puede ser anterior al día de la consulta.';
            }
            if (valor('fecha_fin') !== '' && valor('fecha_fin') < valor('fecha_inicio')) errores.fecha_fin = 'La fecha de fin no puede ser anterior a la de inicio.';
            return mostrarErroresConsulta(form, errores);
        }

        // CU32 paso 2: campos obligatorios y duración de 1 a 365 días.
        function validarPrescripcion(form) {
            const valor = (campo) => form.elements[campo].value.trim();
            const errores = {};
            if (valor('medicamento') === '') errores.medicamento = 'El campo Medicamento es obligatorio.';
            if (valor('dosis') === '') errores.dosis = 'El campo Dosis es obligatorio.';
            if (valor('frecuencia') === '') errores.frecuencia = 'El campo Frecuencia es obligatorio.';
            if (! /^\d+$/.test(valor('duracion_dias')) || Number(valor('duracion_dias')) < 1 || Number(valor('duracion_dias')) > 365) {
                errores.duracion_dias = 'La duración debe estar entre 1 y 365 días.';
            }
            if (valor('via') === '') errores.via = 'Elija la vía de administración.';
            return mostrarErroresConsulta(form, errores);
        }
    </script>
</x-app-layout>
