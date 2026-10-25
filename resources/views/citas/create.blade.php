{{-- CU15 Programar cita (recepción) y A1 Reservar cita (paciente): pasos 1 a 5 en una sola página con Alpine. --}}
@php
    $esAutoservicio = $modo === \App\Services\CitaService::ORIGEN_AUTOSERVICIO;
    $claseSelect = 'campo';
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado :ruta="$esAutoservicio ? 'Citas / Reservar' : 'Citas / Programar'" :titulo="$esAutoservicio ? 'Reservar cita' : 'Programar cita'">
            <a href="{{ route('fichas.disponibilidad') }}" class="font-semibold text-teal-700 hover:underline">Ver médicos y horarios</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6"
         x-data="formularioCita({
            modo: {{ Js::from($modo) }},
            opciones: {{ Js::from($opciones) }},
            inicialPaciente: {{ Js::from($inicialPaciente) }},
            inicial: {{ Js::from($inicial) }},
            especialidades: {{ Js::from($especialidades) }},
            urlFichas: {{ Js::from(route('citas.fichas')) }},
            urlPacientes: {{ Js::from(route('citas.pacientes')) }},
            hoy: {{ Js::from(today()->toDateString()) }},
         })">
        <div class="space-y-6">
            {{-- CU15 A1: sin correo verificado no se puede confirmar --}}
            @unless ($correoVerificado)
                <div class="alerta-aviso font-medium">
                    Verifica tu correo para reservar en línea.
                    <a href="{{ route('registro.reenviar') }}" class="ms-1 font-semibold underline">Reenviar el enlace de verificación</a>
                </div>
            @endunless

            {{-- CU15 paso 8: regla de negocio que impidió guardar (RN03–RN20, RF07) --}}
            @if ($errors->has('cita'))
                <div class="alerta-error font-medium">{{ $errors->first('cita') }}</div>
            @endif

            @if ($esAutoservicio && count($opciones) === 0)
                <div class="alerta-aviso font-medium">
                    Su perfil de paciente no está activo. Comuníquese con recepción.
                </div>
            @endif

            {{-- novalidate: la validación la hace validarCita() (resources/js/citas.js). El servidor valida igual. --}}
            <form x-ref="formulario" method="POST" action="{{ route('citas.guardar') }}" novalidate
                  class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3"
                  @submit="if (! validarCita($event.target, $data, {{ Js::from(today()->toDateString()) }})) { $event.preventDefault(); resumen = false; }">
                @csrf
                <input type="hidden" name="paciente_id" :value="paciente ? paciente.id : ''">
                <input type="hidden" name="especialidad_id" :value="especialidadId">
                <input type="hidden" name="medico_id" :value="medicoId">
                <input type="hidden" name="ficha_id" :value="fichaId">

                <div class="space-y-6 lg:col-span-2">
                    {{-- Pasos 1 y 2: paciente, especialidad, médico y fecha --}}
                    <div class="tarjeta space-y-4">
                        <h2 class="tarjeta-titulo">1. Paciente y especialidad</h2>

                        @if ($esAutoservicio)
                            {{-- A1: «Para mí» y los menores de los que es responsable legal verificado --}}
                            <div class="max-w-md">
                                <x-input-label for="paciente_sel" value="¿Para quién es la cita?" />
                                <select id="paciente_sel" class="{{ $claseSelect }}" :value="paciente ? paciente.id : ''"
                                        @change="elegirPaciente(opciones.find((o) => String(o.id) === $event.target.value))">
                                    <template x-for="o in opciones" :key="o.id">
                                        <option :value="o.id" x-text="(o.esPropio ? 'Para mí' : o.nombre + ' (menor a mi cargo)') + ' · ' + o.codigo" :selected="paciente && o.id === paciente.id"></option>
                                    </template>
                                </select>
                            </div>
                        @else
                            {{-- CU15 paso 1 (incluye CU11): buscador de pacientes activos --}}
                            <div class="flex flex-wrap items-end gap-3" x-show="! paciente">
                                <div class="w-full sm:w-96">
                                    <x-input-label for="busqueda" value="Documento, código o nombre" />
                                    <x-text-input id="busqueda" type="search" x-model="busqueda"
                                                  @keydown.enter.prevent="buscarPacientes()" placeholder="Por ejemplo, 9910004, PAC-000004 o Flores" />
                                </div>
                                <x-secondary-button @click="buscarPacientes()">Buscar</x-secondary-button>
                            </div>
                            <p class="text-sm text-slate-600" x-show="mensajeBusqueda && ! paciente" x-text="mensajeBusqueda"></p>
                            <ul class="divide-y divide-slate-100 rounded-xl border border-slate-200" x-show="resultados.length && ! paciente" x-cloak>
                                <template x-for="r in resultados" :key="r.id">
                                    <li class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm hover:bg-slate-50">
                                        <span><span class="font-semibold text-marino-900" x-text="r.codigo"></span> · <span x-text="r.nombre"></span> · <span x-text="r.documento"></span> · <span x-text="r.edad + ' años, ' + r.sexo"></span></span>
                                        <button type="button" class="accion" @click="elegirPaciente(r)">Elegir</button>
                                    </li>
                                </template>
                            </ul>
                        @endif

                        <div class="rounded-xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm text-teal-900" x-show="paciente" x-cloak>
                            <span class="font-semibold" x-text="paciente?.codigo"></span> · <span x-text="paciente?.nombre"></span>
                            · <span x-text="paciente?.documento"></span> · <span x-text="(paciente?.edad ?? '') + ' años, ' + (paciente?.sexo === 'F' ? 'femenino' : 'masculino')"></span>
                            @unless ($esAutoservicio)
                                <button type="button" class="ms-2 font-semibold text-teal-700 hover:underline" @click="paciente = null; limpiarFichas()">Cambiar</button>
                            @endunless
                        </div>
                        <p class="js-error" data-campo="paciente_id" hidden></p>
                        <x-input-error :messages="$errors->get('paciente_id')" />

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" x-show="paciente" x-cloak>
                            <div>
                                <x-input-label for="especialidad" value="Especialidad" />
                                {{-- RN15: solo las que admiten la edad y el sexo del paciente --}}
                                <select id="especialidad" class="{{ $claseSelect }}" x-model="especialidadId" @change="cambiarEspecialidad()">
                                    <option value="">Seleccione…</option>
                                    <template x-for="e in especialidadesPermitidas" :key="e.id">
                                        <option :value="String(e.id)" x-text="e.nombre" :selected="String(e.id) === especialidadId"></option>
                                    </template>
                                </select>
                                <p class="js-error" data-campo="especialidad_id" hidden></p>
                                <x-input-error :messages="$errors->get('especialidad_id')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="medico" value="Médico" />
                                <select id="medico" class="{{ $claseSelect }}" x-model="medicoId" @change="cargarFichas()" :disabled="! especialidadId">
                                    <option value="">Seleccione…</option>
                                    <template x-for="m in medicos" :key="m.id">
                                        <option :value="String(m.id)" x-text="m.nombre" :selected="String(m.id) === medicoId"></option>
                                    </template>
                                </select>
                                {{-- Mejora a: días y horas en que atiende el médico en esta especialidad --}}
                                <p class="ayuda" x-show="medico" x-text="medico?.atiende" x-cloak></p>
                                <p class="js-error" data-campo="medico_id" hidden></p>
                                <x-input-error :messages="$errors->get('medico_id')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="fecha" value="Fecha" />
                                <x-text-input id="fecha" name="fecha" type="date" min="{{ today()->toDateString() }}"
                                              x-model="fecha" @change="cargarFichas()" />
                                <p class="js-error" data-campo="fecha" hidden></p>
                                <x-input-error :messages="$errors->get('fecha')" class="mt-2" />
                            </div>
                        </div>
                    </div>

                    {{-- CU15 paso 3 / RF06: SOLO los horarios disponibles, como botones de hora --}}
                    <div class="tarjeta space-y-3" x-show="paciente" x-cloak>
                        <h2 class="tarjeta-titulo">2. Horarios disponibles</h2>
                        <p class="text-sm text-slate-500" x-show="cargando">Cargando…</p>
                        <p class="text-sm text-slate-600" x-show="! cargando && mensajeFichas" x-text="mensajeFichas"></p>
                        {{-- Mejora b: el próximo día disponible (hasta 60 días después) --}}
                        <p class="alerta-info" x-show="! cargando && proxima" x-cloak>
                            El próximo día disponible es el <span class="font-semibold" x-text="proxima?.texto"></span>.
                            <button type="button" class="ms-2 font-semibold text-teal-700 hover:underline" @click="irAProxima()">Ir a ese día</button>
                        </p>
                        <p class="text-sm text-slate-500" x-show="! cargando && ! mensajeFichas && ! fichas.length">Elija la especialidad, el médico y la fecha.</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="f in fichas" :key="f.id">
                                <button type="button" @click="fichaId = String(f.id); resumen = false"
                                        :class="String(f.id) === String(fichaId) ? 'ficha-seleccionada' : 'ficha-disponible'"
                                        class="ficha" x-text="f.hora_inicio + '–' + f.hora_fin"></button>
                            </template>
                        </div>
                        <p class="js-error" data-campo="ficha_id" hidden></p>
                        <x-input-error :messages="$errors->get('ficha_id')" class="mt-2" />
                    </div>
                </div>

                <div class="space-y-6 lg:col-span-1">
                    {{-- Paso 4: motivo y seguro --}}
                    <div class="tarjeta space-y-4" x-show="paciente" x-cloak>
                        <h2 class="tarjeta-titulo">3. Motivo y seguro</h2>

                        <div>
                            <x-input-label for="motivo" value="Motivo de la consulta (opcional)" />
                            <textarea id="motivo" name="motivo" rows="3" maxlength="500" x-model="motivo" @input="resumen = false"
                                      class="campo"></textarea>
                            <p class="ayuda"><span x-text="motivo.length"></span>/500</p>
                            <p class="js-error" data-campo="motivo" hidden></p>
                            <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                        </div>

                        {{-- RN20: solo seguros ACTIVOS y verificados del paciente --}}
                        <div>
                            <x-input-label for="seguro_paciente_id" value="Atención con seguro" />
                            <select id="seguro_paciente_id" name="seguro_paciente_id" class="{{ $claseSelect }}" x-model="seguroId" @change="resumen = false">
                                <option value="">Sin seguro (particular)</option>
                                <template x-for="s in (paciente?.seguros ?? [])" :key="s.id">
                                    <option :value="String(s.id)" x-text="s.texto" :selected="String(s.id) === seguroId"></option>
                                </template>
                            </select>
                            <x-input-error :messages="$errors->get('seguro_paciente_id')" class="mt-2" />
                        </div>
                        <div x-show="seguroId">
                            <x-input-label for="codigo_autorizacion_seguro" value="Código de autorización (opcional)" />
                            <x-text-input id="codigo_autorizacion_seguro" name="codigo_autorizacion_seguro" type="text" maxlength="100"
                                          x-model="codigoAutorizacion" ::disabled="! seguroId" />
                            <x-input-error :messages="$errors->get('codigo_autorizacion_seguro')" class="mt-2" />
                        </div>

                        <div x-show="! resumen">
                            <x-primary-button type="button" class="w-full" @click="revisar()">Revisar la cita</x-primary-button>
                        </div>
                    </div>

                    {{-- Paso 5: resumen antes de confirmar --}}
                    <div x-ref="resumen" class="tarjeta space-y-4 border-teal-300 ring-1 ring-teal-200" x-show="resumen" x-cloak>
                        <h2 class="tarjeta-titulo">Resumen de la cita</h2>
                        <dl class="datos-compactos grid grid-cols-1">
                            <div class="dato"><dt>Paciente</dt><dd x-text="paciente ? paciente.codigo + ' · ' + paciente.nombre : ''"></dd></div>
                            <div class="dato"><dt>Especialidad</dt><dd x-text="especialidad?.nombre"></dd></div>
                            <div class="dato"><dt>Médico</dt><dd x-text="medico?.nombre"></dd></div>
                            <div class="dato"><dt>Fecha</dt><dd x-text="fecha ? fecha.split('-').reverse().join('/') : ''"></dd></div>
                            <div class="dato"><dt>Horario</dt><dd x-text="ficha ? ficha.hora_inicio + ' – ' + ficha.hora_fin : ''"></dd></div>
                            <div class="dato"><dt>Modalidad</dt><dd x-text="ficha ? (ficha.modalidad === 'VIRTUAL' ? 'Virtual (recibirá el enlace de videollamada)' : 'Presencial') : ''"></dd></div>
                            <div class="dato"><dt>Consultorio</dt><dd x-text="ficha && ficha.consultorio ? ficha.consultorio + ' (' + ficha.ubicacion + ')' : '—'"></dd></div>
                            <div class="dato"><dt>Seguro</dt><dd x-text="seguro ? seguro.texto + (codigoAutorizacion ? ' · autorización ' + codigoAutorizacion : '') : 'Sin seguro'"></dd></div>
                            <div class="dato"><dt>Motivo</dt><dd x-text="motivo || '—'"></dd></div>
                        </dl>

                        <div class="flex flex-wrap items-center gap-3">
                            @if ($correoVerificado)
                                <x-primary-button>Confirmar cita</x-primary-button>
                            @else
                                <x-primary-button type="button" disabled class="opacity-50 cursor-not-allowed">Confirmar cita</x-primary-button>
                            @endif
                            <x-secondary-button @click="resumen = false">Volver a editar</x-secondary-button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
