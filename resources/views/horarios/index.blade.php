{{-- CU09 Gestionar horarios de atención (RF05): médico, grilla semanal, historial y formulario en la misma página. --}}
@php
    $campos = ['medico_especialidad_id', 'dia_semana', 'hora_inicio', 'hora_fin', 'duracion_ficha_min', 'modalidad', 'consultorio_id', 'vigente_desde', 'vigente_hasta'];
    $volvioFormulario = $errors->hasAny($campos);
    $hoy = today()->toDateString();

    $vacio = ['medico_especialidad_id' => '', 'dia_semana' => '', 'hora_inicio' => '', 'hora_fin' => '', 'duracion_ficha_min' => '',
        'modalidad' => 'PRESENCIAL', 'consultorio_id' => '', 'vigente_desde' => $hoy, 'vigente_hasta' => ''];
    $inicial = $volvioFormulario ? array_merge($vacio, array_map(fn ($valor) => $valor ?? '', \Illuminate\Support\Arr::only(old(), $campos))) : $vacio;

    // CU09 paso 5: duración sugerida de cada especialidad (por medico_especialidad_id).
    $duraciones = $especialidades->pluck('duracion_sugerida_min', 'id');

    $fecha = fn ($valor) => $valor ? \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y') : null;
    $hora = fn ($valor) => substr($valor, 0, 5);
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Horarios de atención
        </h2>
    </x-slot>

    <div class="py-12"
         x-data="{
            abierto: {{ Js::from($volvioFormulario) }},
            form: {{ Js::from($inicial) }},
            duraciones: {{ Js::from($duraciones) }},
            // CU09 paso 5: al elegir la especialidad se propone su duración sugerida (se puede ajustar).
            proponerDuracion() {
                if (this.duraciones[this.form.medico_especialidad_id]) this.form.duracion_ficha_min = this.duraciones[this.form.medico_especialidad_id];
            },
            // CU09 paso 7 / RN08: cuántas fichas salen por día con esta duración.
            get resumen() { return resumenFichas(this.form.hora_inicio, this.form.hora_fin, this.form.duracion_ficha_min); },
            nuevo() {
                this.form = {{ Js::from($vacio) }};
                this.abierto = true;
                this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
            },
         }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU09 paso 1: elegir el médico (solo ACTIVOS) --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <form method="GET" action="{{ route('horarios.listar') }}" class="flex flex-wrap items-end gap-3">
                    <div>
                        <x-input-label for="medico_id" value="Médico" />
                        <select id="medico_id" name="medico_id" onchange="this.form.submit()"
                                class="mt-1 block w-96 max-w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                            <option value="">Seleccione un médico…</option>
                            @foreach ($medicos as $opcion)
                                <option value="{{ $opcion->id }}" @selected($medico?->id === $opcion->id)>{{ $opcion->codigo }} · {{ $opcion->persona->nombre_completo }}</option>
                            @endforeach
                        </select>
                    </div>
                    <noscript><x-primary-button>Ver</x-primary-button></noscript>
                    @if ($medico)
                        <a href="{{ route('medicos.mostrar', $medico->id) }}" class="text-sm text-indigo-600 hover:underline">Ver ficha del médico</a>
                    @endif
                </form>
            </div>

            @if ($errors->hasAny(['operacion', 'id', 'fecha_cierre']) && ! $volvioFormulario)
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id'), $errors->get('fecha_cierre')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @if ($medico)
                {{-- CU09 paso 2: grilla semanal de horarios vigentes --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h3 class="text-lg font-medium text-gray-900">Horarios vigentes de {{ $medico->persona->nombre_completo }}</h3>
                        {{-- CU09 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo horario</x-primary-button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-7 gap-3">
                        @foreach ($dias as $numero => $nombreDia)
                            <div class="rounded-md border border-gray-200 bg-gray-50 p-2 space-y-2 min-h-24">
                                <p class="text-center text-xs font-semibold uppercase tracking-wider text-gray-600">{{ $nombreDia }}</p>
                                @foreach ($vigentes->get($numero, collect()) as $horario)
                                    <div class="rounded-md bg-white p-2 text-xs shadow-sm space-y-1">
                                        <a href="{{ route('horarios.mostrar', $horario->id) }}" class="block font-semibold text-indigo-700 hover:underline">
                                            {{ $hora($horario->hora_inicio) }}–{{ $hora($horario->hora_fin) }}
                                        </a>
                                        <p class="font-medium text-gray-800">{{ $horario->especialidad }}</p>
                                        <p class="text-gray-600">Fichas de {{ $horario->duracion_ficha_min }} min</p>
                                        <p class="text-gray-600">{{ $horario->modalidad === 'VIRTUAL' ? 'Virtual' : $horario->consultorio }}</p>
                                        <p class="text-gray-500">Desde {{ $fecha($horario->vigente_desde) }}{{ $horario->vigente_hasta ? ' hasta ' . $fecha($horario->vigente_hasta) : '' }}</p>
                                        {{-- Fichas futuras: habilitadas / bloqueadas / ocupadas --}}
                                        <p class="flex flex-wrap gap-1">
                                            <span class="rounded bg-green-100 px-1 text-green-800" title="Habilitadas">{{ $horario->fichas_habilitadas }} libres</span>
                                            <span class="rounded bg-gray-200 px-1 text-gray-700" title="Bloqueadas">{{ $horario->fichas_bloqueadas }} bloq.</span>
                                            <span class="rounded bg-blue-100 px-1 text-blue-800" title="Ocupadas">{{ $horario->fichas_ocupadas }} ocup.</span>
                                        </p>

                                        {{-- CU09 A2: generar más semanas (idempotente) --}}
                                        <form method="POST" action="{{ route('horarios.generar') }}">
                                            @csrf
                                            <input type="hidden" name="id" value="{{ $horario->id }}">
                                            <button class="text-indigo-600 hover:underline">Generar {{ $semanas }} semanas</button>
                                        </form>

                                        {{-- CU09 A1: cerrar la vigencia (un horario no se edita) --}}
                                        <details>
                                            <summary class="cursor-pointer text-red-700 hover:underline">Cerrar vigencia</summary>
                                            <form method="POST" action="{{ route('horarios.eliminar') }}" class="mt-1 space-y-1"
                                                  onsubmit="return confirm({{ Js::from('¿Cerrar el horario del '.mb_strtolower($nombreDia).' '.$hora($horario->hora_inicio).'–'.$hora($horario->hora_fin).'? Se eliminarán sus fichas libres posteriores a la fecha de cierre.') }});">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $horario->id }}">
                                                <input type="date" name="fecha_cierre" value="{{ $hoy }}" min="{{ $hoy }}" required
                                                       class="block w-full rounded-md border-gray-300 text-xs shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <x-danger-button class="!px-2 !py-1 !text-[10px]">Cerrar</x-danger-button>
                                            </form>
                                        </details>
                                    </div>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                    <p class="text-xs text-gray-500">Para cambiar un horario, cierre su vigencia y registre uno nuevo desde el día siguiente.</p>
                </div>

                {{-- CU09 paso 2: historial de horarios cerrados --}}
                <div class="bg-white shadow-sm sm:rounded-lg p-6 space-y-4">
                    <h3 class="text-lg font-medium text-gray-900">Horarios cerrados</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Día</th>
                                    <th class="px-3 py-2">Horario</th>
                                    <th class="px-3 py-2">Especialidad</th>
                                    <th class="px-3 py-2">Modalidad</th>
                                    <th class="px-3 py-2">Consultorio</th>
                                    <th class="px-3 py-2">Vigencia</th>
                                    <th class="px-3 py-2">Estado</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($cerrados as $horario)
                                    <tr>
                                        <td class="px-3 py-2">{{ $dias[$horario->dia_semana] }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap"><a href="{{ route('horarios.mostrar', $horario->id) }}" class="text-indigo-600 hover:underline">{{ $hora($horario->hora_inicio) }}–{{ $hora($horario->hora_fin) }}</a></td>
                                        <td class="px-3 py-2">{{ $horario->especialidad }}</td>
                                        <td class="px-3 py-2">{{ $horario->modalidad === 'VIRTUAL' ? 'Virtual' : 'Presencial' }}</td>
                                        <td class="px-3 py-2">{{ $horario->consultorio ?? '—' }}</td>
                                        <td class="px-3 py-2 whitespace-nowrap">{{ $fecha($horario->vigente_desde) }} – {{ $fecha($horario->vigente_hasta) ?? 'sin fin' }}</td>
                                        <td class="px-3 py-2"><x-estado :estado="$horario->estado" /></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">El médico no tiene horarios cerrados.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- CU09 pasos 4 a 7: formulario de registro --}}
                <div x-ref="formulario" x-show="abierto" x-cloak class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <section class="max-w-3xl space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">Nuevo horario de {{ $medico->persona->nombre_completo }}</h2>
                            <p class="mt-1 text-sm text-gray-600">Al guardar se generan las fichas de las próximas {{ $semanas }} semanas.</p>
                        </header>

                        @if ($especialidades->isEmpty())
                            <p class="rounded-md bg-yellow-50 p-3 text-sm text-yellow-800">El médico no ejerce ninguna especialidad activa: asígnele una en Médicos antes de registrar horarios.</p>
                        @endif

                        {{-- novalidate: la validación la hace validarHorario() (más abajo), en español --}}
                        <form method="POST" action="{{ route('horarios.guardar') }}" novalidate class="space-y-6"
                              @submit="if (! validarHorario($event.target)) $event.preventDefault()">
                            @csrf

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                {{-- CU09 paso 4: solo las especialidades que ejerce (el valor es medico_especialidad_id) --}}
                                <div>
                                    <x-input-label for="medico_especialidad_id" value="Especialidad" />
                                    <select id="medico_especialidad_id" name="medico_especialidad_id" x-model="form.medico_especialidad_id" @change="proponerDuracion()"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="">Seleccione…</option>
                                        @foreach ($especialidades as $especialidad)
                                            <option value="{{ $especialidad->id }}">{{ $especialidad->nombre }} ({{ $especialidad->duracion_sugerida_min }} min sugeridos)</option>
                                        @endforeach
                                    </select>
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="medico_especialidad_id" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('medico_especialidad_id')" class="mt-2" /> @endif
                                </div>
                                <div>
                                    <x-input-label for="dia_semana" value="Día de la semana" />
                                    <select id="dia_semana" name="dia_semana" x-model="form.dia_semana"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="">Seleccione…</option>
                                        @foreach ($dias as $numero => $nombreDia)
                                            <option value="{{ $numero }}">{{ $nombreDia }}</option>
                                        @endforeach
                                    </select>
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="dia_semana" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('dia_semana')" class="mt-2" /> @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                                <div>
                                    <x-input-label for="hora_inicio" value="Hora inicial" />
                                    <x-text-input id="hora_inicio" name="hora_inicio" type="time" step="300" class="mt-1 block w-full" x-model="form.hora_inicio" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="hora_inicio" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('hora_inicio')" class="mt-2" /> @endif
                                </div>
                                <div>
                                    <x-input-label for="hora_fin" value="Hora final" />
                                    <x-text-input id="hora_fin" name="hora_fin" type="time" step="300" class="mt-1 block w-full" x-model="form.hora_fin" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="hora_fin" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('hora_fin')" class="mt-2" /> @endif
                                </div>
                                <div>
                                    <x-input-label for="duracion_ficha_min" value="Duración de la ficha (min)" />
                                    <x-text-input id="duracion_ficha_min" name="duracion_ficha_min" type="number" min="5" max="240" step="1" class="mt-1 block w-full" x-model="form.duracion_ficha_min" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="duracion_ficha_min" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('duracion_ficha_min')" class="mt-2" /> @endif
                                </div>
                            </div>
                            {{-- RF05 / RN08: cuántas fichas salen por día --}}
                            <p class="text-sm" :class="resumen.ok ? 'text-green-700' : 'text-gray-500'" x-text="resumen.texto"></p>

                            {{-- CU09 paso 6: modalidad y consultorio --}}
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label value="Modalidad" />
                                    <div class="mt-2 flex gap-6 text-sm">
                                        <label class="flex items-center gap-2"><input type="radio" name="modalidad" value="PRESENCIAL" x-model="form.modalidad" class="text-indigo-600 focus:ring-indigo-500"> Presencial</label>
                                        <label class="flex items-center gap-2"><input type="radio" name="modalidad" value="VIRTUAL" x-model="form.modalidad" @change="form.consultorio_id = ''" class="text-indigo-600 focus:ring-indigo-500"> Virtual</label>
                                    </div>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('modalidad')" class="mt-2" /> @endif
                                </div>
                                <div x-show="form.modalidad === 'PRESENCIAL'">
                                    <x-input-label for="consultorio_id" value="Consultorio" />
                                    {{-- Un horario virtual no lleva consultorio: deshabilitado no se envía --}}
                                    <select id="consultorio_id" name="consultorio_id" x-model="form.consultorio_id" :disabled="form.modalidad !== 'PRESENCIAL'"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="">Seleccione…</option>
                                        @foreach ($consultorios as $consultorio)
                                            <option value="{{ $consultorio->id }}">{{ $consultorio->nombre }}</option>
                                        @endforeach
                                    </select>
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="consultorio_id" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('consultorio_id')" class="mt-2" /> @endif
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <x-input-label for="vigente_desde" value="Vigente desde" />
                                    <x-text-input id="vigente_desde" name="vigente_desde" type="date" min="{{ $hoy }}" class="mt-1 block w-full" x-model="form.vigente_desde" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="vigente_desde" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('vigente_desde')" class="mt-2" /> @endif
                                </div>
                                <div>
                                    <x-input-label for="vigente_hasta" value="Vigente hasta (opcional)" />
                                    <x-text-input id="vigente_hasta" name="vigente_hasta" type="date" ::min="form.vigente_desde" class="mt-1 block w-full" x-model="form.vigente_hasta" />
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="vigente_hasta" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('vigente_hasta')" class="mt-2" /> @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-4">
                                <x-primary-button>Registrar horario</x-primary-button>
                                <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                            </div>
                        </form>
                    </section>
                </div>
            @else
                <p class="text-sm text-gray-600">Elija un médico para ver y gestionar sus horarios.</p>
            @endif
        </div>
    </div>

    {{-- CU09 paso 7: validación en el cliente (el servidor valida lo mismo) --}}
    <script>
        // Minutos desde las 00:00 de una hora HH:MM (null si no es válida).
        function minutos(hora) {
            return /^\d{2}:\d{2}$/.test(hora ?? '') ? Number(hora.slice(0, 2)) * 60 + Number(hora.slice(3, 5)) : null;
        }

        // RF05 / RN08: «8 fichas de 30 minutos» o por qué no se puede calcular.
        function resumenFichas(inicio, fin, duracion) {
            const a = minutos(inicio), b = minutos(fin), d = Number(duracion);
            if (a === null || b === null || ! d) return { ok: false, texto: 'Indique las horas y la duración para ver cuántas fichas salen por día.' };
            if (b <= a) return { ok: false, texto: 'La hora final debe ser posterior a la hora inicial' };
            if ((b - a) % d !== 0) return { ok: false, texto: 'La duración de ' + d + ' minutos no divide exactamente el rango de ' + inicio + ' a ' + fin + ' (' + (b - a) + ' minutos)' };
            return { ok: true, texto: ((b - a) / d) + ' fichas de ' + d + ' minutos por día' };
        }

        function validarHorario(form) {
            const valor = (campo) => String(form.elements[campo]?.value ?? '').trim();
            const errores = {};
            const hoy = {{ Js::from(today()->toDateString()) }};

            if (valor('medico_especialidad_id') === '') errores.medico_especialidad_id = 'Elija la especialidad.';
            if (valor('dia_semana') === '') errores.dia_semana = 'Elija el día de la semana.';
            if (valor('hora_inicio') === '') errores.hora_inicio = 'El campo Hora inicial es obligatorio.';
            if (valor('hora_fin') === '') {
                errores.hora_fin = 'El campo Hora final es obligatorio.';
            } else if (minutos(valor('hora_inicio')) !== null && minutos(valor('hora_fin')) <= minutos(valor('hora_inicio'))) {
                errores.hora_fin = 'La hora final debe ser posterior a la hora inicial';
            }

            const duracion = valor('duracion_ficha_min');
            if (duracion === '') {
                errores.duracion_ficha_min = 'El campo Duración de la ficha es obligatorio.';
            } else if (! /^\d+$/.test(duracion) || Number(duracion) < 5 || Number(duracion) > 240) {
                errores.duracion_ficha_min = 'La duración debe estar entre 5 y 240 minutos.';
            } else if (! errores.hora_inicio && ! errores.hora_fin) {
                const resumen = resumenFichas(valor('hora_inicio'), valor('hora_fin'), duracion);
                if (! resumen.ok) errores.duracion_ficha_min = resumen.texto;
            }

            // CU09 paso 6: consultorio solo (y siempre) en presencial.
            const modalidad = form.querySelector('input[name="modalidad"]:checked')?.value ?? '';
            if (modalidad === 'PRESENCIAL' && valor('consultorio_id') === '') errores.consultorio_id = 'El consultorio es obligatorio para la atención presencial';

            if (valor('vigente_desde') === '') {
                errores.vigente_desde = 'El campo Vigente desde es obligatorio.';
            } else if (valor('vigente_desde') < hoy) {
                errores.vigente_desde = 'La vigencia no puede empezar en una fecha pasada';
            }
            if (valor('vigente_hasta') !== '' && valor('vigente_hasta') < valor('vigente_desde')) {
                errores.vigente_hasta = 'La fecha Vigente hasta no puede ser anterior a Vigente desde.';
            }

            form.querySelectorAll('.js-error').forEach((p) => { p.hidden = true; p.textContent = ''; });
            Object.entries(errores).forEach(([campo, mensaje]) => {
                const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                if (p) { p.textContent = mensaje; p.hidden = false; }
            });
            return Object.keys(errores).length === 0;
        }
    </script>
</x-app-layout>
