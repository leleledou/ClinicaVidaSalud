{{-- CU15 Programar cita (recepción) y A1 Reservar cita (paciente): pasos 1 a 5 en una sola página con Alpine. --}}
@php
    $esAutoservicio = $modo === \App\Services\CitaService::ORIGEN_AUTOSERVICIO;
    $claseSelect = 'mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm';
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $esAutoservicio ? 'Reservar cita' : 'Programar cita' }}</h2>
            <a href="{{ route('fichas.disponibilidad') }}" class="text-sm text-indigo-600 hover:underline">Ver médicos y horarios</a>
        </div>
    </x-slot>

    <div class="py-12"
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
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU15 A1: sin correo verificado no se puede confirmar --}}
            @unless ($correoVerificado)
                <div class="rounded-md bg-yellow-50 p-4 text-sm font-medium text-yellow-800">
                    Verifica tu correo para reservar en línea.
                    <a href="{{ route('registro.reenviar') }}" class="ms-1 underline">Reenviar el enlace de verificación</a>
                </div>
            @endunless

            {{-- CU15 paso 8: regla de negocio que impidió guardar (RN03–RN20, RF07) --}}
            @if ($errors->has('cita'))
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">{{ $errors->first('cita') }}</div>
            @endif

            @if ($esAutoservicio && count($opciones) === 0)
                <div class="rounded-md bg-yellow-50 p-4 text-sm font-medium text-yellow-800">
                    Su perfil de paciente no está activo. Comuníquese con recepción.
                </div>
            @endif

            {{-- novalidate: la validación la hace validarCita() (resources/js/citas.js). El servidor valida igual. --}}
            <form x-ref="formulario" method="POST" action="{{ route('citas.guardar') }}" novalidate class="space-y-6"
                  @submit="if (! validarCita($event.target, $data, {{ Js::from(today()->toDateString()) }})) { $event.preventDefault(); resumen = false; }">
                @csrf
                <input type="hidden" name="paciente_id" :value="paciente ? paciente.id : ''">
                <input type="hidden" name="especialidad_id" :value="especialidadId">
                <input type="hidden" name="medico_id" :value="medicoId">
                <input type="hidden" name="ficha_id" :value="fichaId">

                {{-- Paso 1: paciente --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">1. Paciente</h3>

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
                            <div>
                                <x-input-label for="busqueda" value="Documento, código o nombre" />
                                <x-text-input id="busqueda" type="search" class="mt-1 block w-80" x-model="busqueda"
                                              @keydown.enter.prevent="buscarPacientes()" placeholder="Por ejemplo, 9910004, PAC-000004 o Flores" />
                            </div>
                            <x-secondary-button @click="buscarPacientes()">Buscar</x-secondary-button>
                        </div>
                        <p class="text-sm text-gray-600" x-show="mensajeBusqueda && ! paciente" x-text="mensajeBusqueda"></p>
                        <ul class="divide-y divide-gray-100 rounded-md border border-gray-200" x-show="resultados.length && ! paciente" x-cloak>
                            <template x-for="r in resultados" :key="r.id">
                                <li class="flex items-center justify-between gap-4 px-3 py-2 text-sm">
                                    <span><span class="font-medium" x-text="r.codigo"></span> · <span x-text="r.nombre"></span> · <span x-text="r.documento"></span> · <span x-text="r.edad + ' años, ' + r.sexo"></span></span>
                                    <x-secondary-button @click="elegirPaciente(r)">Elegir</x-secondary-button>
                                </li>
                            </template>
                        </ul>
                    @endif

                    <div class="rounded-md bg-gray-50 p-3 text-sm" x-show="paciente" x-cloak>
                        <span class="font-medium" x-text="paciente?.codigo"></span> · <span x-text="paciente?.nombre"></span>
                        · <span x-text="paciente?.documento"></span> · <span x-text="(paciente?.edad ?? '') + ' años, ' + (paciente?.sexo === 'F' ? 'femenino' : 'masculino')"></span>
                        @unless ($esAutoservicio)
                            <button type="button" class="ms-2 text-indigo-600 hover:underline" @click="paciente = null; limpiarFichas()">Cambiar</button>
                        @endunless
                    </div>
                    <p class="js-error text-sm text-red-600" data-campo="paciente_id" hidden></p>
                    <x-input-error :messages="$errors->get('paciente_id')" />
                </div>

                {{-- Pasos 2 y 3: especialidad, médico, fecha y fichas disponibles --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4" x-show="paciente" x-cloak>
                    <h3 class="text-lg font-medium text-gray-900">2. Especialidad, médico y horario</h3>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="especialidad" value="Especialidad" />
                            {{-- RN15: solo las que admiten la edad y el sexo del paciente --}}
                            <select id="especialidad" class="{{ $claseSelect }}" x-model="especialidadId" @change="cambiarEspecialidad()">
                                <option value="">Seleccione…</option>
                                <template x-for="e in especialidadesPermitidas" :key="e.id">
                                    <option :value="String(e.id)" x-text="e.nombre" :selected="String(e.id) === especialidadId"></option>
                                </template>
                            </select>
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="especialidad_id" hidden></p>
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
                            <p class="mt-1 text-xs text-gray-600" x-show="medico" x-text="medico?.atiende" x-cloak></p>
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="medico_id" hidden></p>
                            <x-input-error :messages="$errors->get('medico_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="fecha" value="Fecha" />
                            <x-text-input id="fecha" name="fecha" type="date" min="{{ today()->toDateString() }}" class="mt-1 block w-full"
                                          x-model="fecha" @change="cargarFichas()" />
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="fecha" hidden></p>
                            <x-input-error :messages="$errors->get('fecha')" class="mt-2" />
                        </div>
                    </div>

                    {{-- CU15 paso 3 / RF06: SOLO los horarios disponibles, como botones de hora --}}
                    <div>
                        <p class="text-sm font-medium text-gray-700">Horarios disponibles</p>
                        <p class="mt-1 text-sm text-gray-500" x-show="cargando">Cargando…</p>
                        <p class="mt-1 text-sm text-gray-600" x-show="! cargando && mensajeFichas" x-text="mensajeFichas"></p>
                        {{-- Mejora b: el próximo día disponible (hasta 60 días después) --}}
                        <p class="mt-1 text-sm text-gray-700" x-show="! cargando && proxima" x-cloak>
                            El próximo día disponible es el <span class="font-medium" x-text="proxima?.texto"></span>.
                            <button type="button" class="ms-2 text-indigo-600 font-semibold hover:underline" @click="irAProxima()">Ir a ese día</button>
                        </p>
                        <p class="mt-1 text-sm text-gray-500" x-show="! cargando && ! mensajeFichas && ! fichas.length">Elija la especialidad, el médico y la fecha.</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <template x-for="f in fichas" :key="f.id">
                                <button type="button" @click="fichaId = String(f.id); resumen = false"
                                        :class="String(f.id) === String(fichaId) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-300 hover:bg-green-50'"
                                        class="rounded-md border px-3 py-1 text-sm font-semibold" x-text="f.hora_inicio + '–' + f.hora_fin"></button>
                            </template>
                        </div>
                        <p class="js-error mt-2 text-sm text-red-600" data-campo="ficha_id" hidden></p>
                        <x-input-error :messages="$errors->get('ficha_id')" class="mt-2" />
                    </div>
                </div>

                {{-- Paso 4: motivo y seguro --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4" x-show="paciente" x-cloak>
                    <h3 class="text-lg font-medium text-gray-900">3. Motivo y seguro</h3>

                    <div>
                        <x-input-label for="motivo" value="Motivo de la consulta (opcional)" />
                        <textarea id="motivo" name="motivo" rows="3" maxlength="500" x-model="motivo" @input="resumen = false"
                                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                        <p class="mt-1 text-xs text-gray-500"><span x-text="motivo.length"></span>/500</p>
                        <p class="js-error mt-2 text-sm text-red-600" data-campo="motivo" hidden></p>
                        <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                    </div>

                    {{-- RN20: solo seguros ACTIVOS y verificados del paciente --}}
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                            <x-text-input id="codigo_autorizacion_seguro" name="codigo_autorizacion_seguro" type="text" maxlength="100" class="mt-1 block w-full"
                                          x-model="codigoAutorizacion" ::disabled="! seguroId" />
                            <x-input-error :messages="$errors->get('codigo_autorizacion_seguro')" class="mt-2" />
                        </div>
                    </div>

                    <div x-show="! resumen">
                        <x-primary-button type="button" @click="revisar()">Revisar la cita</x-primary-button>
                    </div>
                </div>

                {{-- Paso 5: resumen antes de confirmar --}}
                <div x-ref="resumen" class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4 border-2 border-indigo-200" x-show="resumen" x-cloak>
                    <h3 class="text-lg font-medium text-gray-900">Resumen de la cita</h3>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
                        <div><dt class="font-medium text-gray-500">Paciente</dt><dd x-text="paciente ? paciente.codigo + ' · ' + paciente.nombre : ''"></dd></div>
                        <div><dt class="font-medium text-gray-500">Especialidad</dt><dd x-text="especialidad?.nombre"></dd></div>
                        <div><dt class="font-medium text-gray-500">Médico</dt><dd x-text="medico?.nombre"></dd></div>
                        <div><dt class="font-medium text-gray-500">Fecha</dt><dd x-text="fecha ? fecha.split('-').reverse().join('/') : ''"></dd></div>
                        <div><dt class="font-medium text-gray-500">Horario</dt><dd x-text="ficha ? ficha.hora_inicio + ' – ' + ficha.hora_fin : ''"></dd></div>
                        <div><dt class="font-medium text-gray-500">Modalidad</dt><dd x-text="ficha ? (ficha.modalidad === 'VIRTUAL' ? 'Virtual (recibirá el enlace de videollamada)' : 'Presencial') : ''"></dd></div>
                        <div><dt class="font-medium text-gray-500">Consultorio</dt><dd x-text="ficha && ficha.consultorio ? ficha.consultorio + ' (' + ficha.ubicacion + ')' : '—'"></dd></div>
                        <div><dt class="font-medium text-gray-500">Seguro</dt><dd x-text="seguro ? seguro.texto + (codigoAutorizacion ? ' · autorización ' + codigoAutorizacion : '') : 'Sin seguro'"></dd></div>
                        <div class="sm:col-span-2"><dt class="font-medium text-gray-500">Motivo</dt><dd x-text="motivo || '—'"></dd></div>
                    </dl>

                    <div class="flex items-center gap-4">
                        @if ($correoVerificado)
                            <x-primary-button>Confirmar cita</x-primary-button>
                        @else
                            <x-primary-button type="button" disabled class="opacity-50 cursor-not-allowed">Confirmar cita</x-primary-button>
                        @endif
                        <x-secondary-button @click="resumen = false">Volver a editar</x-secondary-button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
