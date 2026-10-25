{{-- CU16 pasos 2 a 4 (reprogramar: mismo médico) y A1 (reasignar: otro médico de la misma especialidad).
     Variables: $cita, $reasignar (bool), $medicosReasignar (A1), $atiende (días del médico actual). --}}
@php
    $accion = $reasignar ? 'reasignar' : 'reprogramar';
    $asignacion = $cita->ficha->horario->medicoEspecialidad;
    $fechaActual = \Illuminate\Support\Carbon::parse($cita->ficha->fecha);
    $actual = $fechaActual->format('d/m/Y') . ' ' . substr($cita->ficha->hora_inicio, 0, 5) . '–' . substr($cita->ficha->hora_fin, 0, 5)
        . ' con ' . \App\Services\CitaService::tituloMedico($asignacion->medico->persona);
    $volvio = old('accion') === $accion;
    $claseSelect = 'campo';
@endphp

<div x-data="formularioCambioFicha({
        reasignar: {{ Js::from($reasignar) }},
        urlFichas: {{ Js::from(route('citas.fichas')) }},
        especialidadId: {{ Js::from($asignacion->especialidad_id) }},
        medicoId: {{ Js::from($asignacion->medico_id) }},
        medicos: {{ Js::from($reasignar ? $medicosReasignar : []) }},
        codigo: {{ Js::from($cita->codigo) }},
        atiende: {{ Js::from($atiende ?? '') }},
        inicial: {{ Js::from($volvio ? ['medico_id' => old('medico_id'), 'fecha' => old('fecha'), 'ficha_id' => old('ficha_id'), 'motivo' => old('motivo')] : []) }},
        hoy: {{ Js::from(today()->toDateString()) }},
     })">
    {{-- novalidate: la validación la hace validarCambioFicha() (resources/js/citas.js). El servidor valida igual. --}}
    <form x-ref="formulario" method="POST" action="{{ route($reasignar ? 'citas.reasignar' : 'citas.modificar') }}" novalidate class="space-y-4"
          @submit="enviar($event)">
        @csrf
        <input type="hidden" name="id" value="{{ $cita->id }}">
        <input type="hidden" name="accion" value="{{ $accion }}">
        <input type="hidden" name="ficha_id" :value="fichaId">
        @if ($reasignar)
            <input type="hidden" name="medico_id" :value="medicoId">
        @endif

        <p class="text-sm text-slate-700">Ficha actual: <span class="font-semibold text-marino-900">{{ $actual }}</span> ({{ $asignacion->especialidad->nombre }})</p>
        {{-- Mejora a: días y horas en que atiende el médico (el actual o el elegido en A1) --}}
        <p class="ayuda" x-show="atiende" x-text="atiende"></p>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($reasignar)
                {{-- A1: otro médico ACTIVO de la misma especialidad --}}
                <div>
                    <x-input-label for="medico_{{ $accion }}" value="Nuevo médico" />
                    <select id="medico_{{ $accion }}" class="{{ $claseSelect }}" x-model="medicoId" @change="cargarFichas()">
                        <option value="">Seleccione…</option>
                        <template x-for="m in medicos" :key="m.id">
                            <option :value="String(m.id)" x-text="m.nombre" :selected="String(m.id) === medicoId"></option>
                        </template>
                    </select>
                    <p class="mt-1 text-sm text-slate-500" x-show="! medicos.length">No hay otros médicos activos en esta especialidad.</p>
                    <p class="js-error" data-campo="medico_id" hidden></p>
                    @if ($volvio) <x-input-error :messages="$errors->get('medico_id')" class="mt-2" /> @endif
                </div>
            @endif
            {{-- Paso 3: elegir la fecha --}}
            <div>
                <x-input-label for="fecha_{{ $accion }}" value="Nueva fecha" />
                <x-text-input id="fecha_{{ $accion }}" name="fecha" type="date" min="{{ today()->toDateString() }}"
                              x-model="fecha" @change="cargarFichas()" />
                <p class="js-error" data-campo="fecha" hidden></p>
                @if ($volvio) <x-input-error :messages="$errors->get('fecha')" class="mt-2" /> @endif
            </div>
        </div>

        {{-- Paso 3 / RF06: SOLO los horarios disponibles, como botones de hora --}}
        <div>
            <p class="etiqueta">Horarios disponibles</p>
            <p class="mt-1 text-sm text-slate-500" x-show="cargando">Cargando…</p>
            <p class="mt-1 text-sm text-slate-600" x-show="! cargando && mensajeFichas" x-text="mensajeFichas"></p>
            {{-- Mejora b: el próximo día disponible (hasta 60 días después) --}}
            <p class="alerta-info mt-1" x-show="! cargando && proxima" x-cloak>
                El próximo día disponible es el <span class="font-medium" x-text="proxima?.texto"></span>.
                <button type="button" class="ms-2 text-teal-700 font-semibold hover:underline" @click="irAProxima()">Ir a ese día</button>
            </p>
            <p class="mt-1 text-sm text-slate-500" x-show="! cargando && ! mensajeFichas && ! fichas.length">{{ $reasignar ? 'Elija el médico y la fecha.' : 'Elija la fecha.' }}</p>
            <div class="mt-2 flex flex-wrap gap-2">
                <template x-for="f in fichas" :key="f.id">
                    <button type="button" @click="fichaId = String(f.id); resumen = false"
                            :class="String(f.id) === String(fichaId) ? 'ficha-seleccionada' : 'ficha-disponible'"
                            class="ficha" x-text="f.hora_inicio + '–' + f.hora_fin"></button>
                </template>
            </div>
            <p class="js-error" data-campo="ficha_id" hidden></p>
            @if ($volvio) <x-input-error :messages="$errors->get('ficha_id')" class="mt-2" /> @endif
        </div>

        {{-- Paso 4: motivo opcional --}}
        <div>
            <x-input-label for="motivo_{{ $accion }}" value="Motivo del cambio (opcional)" />
            <textarea id="motivo_{{ $accion }}" name="motivo" rows="2" maxlength="500" x-model="motivo" @input="resumen = false"
                      class="campo"></textarea>
            <p class="js-error" data-campo="motivo" hidden></p>
            @if ($volvio) <x-input-error :messages="$errors->get('motivo')" class="mt-2" /> @endif
        </div>

        <div x-show="! resumen">
            <x-primary-button type="button" @click="revisar()">Revisar el cambio</x-primary-button>
        </div>

        {{-- Paso 4: resumen «ficha actual → nueva» antes de confirmar --}}
        <div class="space-y-3 rounded-xl border border-teal-300 bg-teal-50 p-4" x-show="resumen" x-cloak>
            <p class="text-sm text-marino-900"><span class="font-semibold">{{ $actual }}</span> → <span class="font-semibold" x-text="textoNueva"></span></p>
            @if ($reasignar)
                <p class="text-sm text-slate-600">La cita {{ $cita->codigo }} quedará cancelada y se registrará una cita nueva que la reemplaza.</p>
            @else
                <p class="text-sm text-slate-600">La cita volverá a quedar PROGRAMADA y deberá confirmarse otra vez.</p>
            @endif
            <div class="flex flex-wrap items-center gap-3">
                <x-primary-button>{{ $reasignar ? 'Confirmar la reasignación' : 'Confirmar la reprogramación' }}</x-primary-button>
                <x-secondary-button @click="resumen = false">Volver a editar</x-secondary-button>
            </div>
        </div>
    </form>
</div>
