{{-- CU25 Gestionar bloqueos de agenda (RF-12): filtros, lista y formulario de registro en dos pasos
     (el primer envío muestra el resumen; «Confirmar bloqueo» reenvía los mismos datos con confirmar=1). --}}
@php
    $nombresAlcance = ['CLINICA' => 'Toda la clínica (feriado)', 'CONSULTORIO' => 'Consultorio (cierre)', 'MEDICO' => 'Médico (ausencia)'];
    $camposFormulario = ['alcance', 'medico_id', 'consultorio_id', 'inicio', 'fin', 'motivo'];
    $resumen = session('resumen');
    // Los errores del registro vuelven con lo escrito (old); los de levantar, no.
    $volvioFormulario = old('alcance') !== null;
    // El formulario se abre si el servidor devolvió errores del registro o el resumen del primer paso.
    $abrirFormulario = $volvioFormulario || $errors->hasAny($camposFormulario) || $resumen !== null;
    $hayFiltros = $alcance !== null || $situacion !== null;

    $situacionDe = fn ($bloqueo) => match (true) {
        $bloqueo->inicio->greaterThan($ahora) => 'proximos',
        $bloqueo->fin->greaterThan($ahora) => 'en_curso',
        default => 'terminados',
    };
    $claseSituacion = ['proximos' => 'insignia-programada', 'en_curso' => 'insignia-inasistencia', 'terminados' => 'insignia-inactivo'];
    // El selector de fecha y hora no admite minutos pasados.
    $minimo = now()->format('Y-m-d\TH:i');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Catálogos / Bloqueos de agenda" titulo="Bloqueos de agenda" />
    </x-slot>

    <div class="space-y-6"
         x-data="{
            abierto: {{ Js::from($abrirFormulario) }},
            alcance: {{ Js::from(old('alcance', '')) }},
            nuevo() {
                this.abierto = true;
                limpiarErroresJs(this.$refs.formulario);
                this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
            },
         }">
        {{-- Errores de levantar (no son del formulario) --}}
        @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
            <div class="alerta-error font-medium">
                <ul class="space-y-1">
                    @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- CU25 paso 2: filtros --}}
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Filtros</h2>
            <form method="GET" action="{{ route('bloqueos.listar') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <x-input-label for="filtro_alcance" value="Alcance" />
                    <select id="filtro_alcance" name="alcance" class="campo">
                        <option value="">Todos</option>
                        @foreach ($alcances as $valor)
                            <option value="{{ $valor }}" @selected($alcance === $valor)>{{ $nombresAlcance[$valor] }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="situacion" value="Situación" />
                    <select id="situacion" name="situacion" class="campo">
                        <option value="">Todas</option>
                        @foreach ($situaciones as $valor => $texto)
                            <option value="{{ $valor }}" @selected($situacion === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <x-primary-button>Filtrar</x-primary-button>
                    @if ($hayFiltros)
                        <a href="{{ route('bloqueos.listar') }}" class="btn-secundario">Limpiar</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- CU25 pasos 1-2: lista --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Bloqueos registrados</h2>
                        {{-- CU25 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo bloqueo</x-primary-button>
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Alcance</th>
                                    <th>Destino</th>
                                    <th>Inicio</th>
                                    <th>Fin</th>
                                    <th>Motivo</th>
                                    <th>Fichas bloqueadas</th>
                                    <th>Situación</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($bloqueos as $bloqueo)
                                    @php $estadoBloqueo = $situacionDe($bloqueo); @endphp
                                    <tr class="align-top">
                                        <td class="whitespace-nowrap">{{ $bloqueo->alcance }}</td>
                                        <td>
                                            <a href="{{ route('bloqueos.mostrar', $bloqueo->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">
                                                {{ $bloqueo->destino() }}
                                            </a>
                                        </td>
                                        <td class="whitespace-nowrap">{{ $bloqueo->inicio->format('d/m/Y H:i') }}</td>
                                        <td class="whitespace-nowrap">{{ $bloqueo->fin->format('d/m/Y H:i') }}</td>
                                        <td class="text-slate-600">{{ $bloqueo->motivo }}</td>
                                        <td>{{ $bloqueo->fichas_bloqueadas }}</td>
                                        <td>
                                            <span class="insignia {{ $claseSituacion[$estadoBloqueo] }}">{{ $situaciones[$estadoBloqueo] }}</span>
                                        </td>
                                        <td>
                                            <div class="acciones">
                                                <a href="{{ route('bloqueos.mostrar', $bloqueo->id) }}" class="accion">Ver</a>

                                                {{-- CU25 A2: levantar (el terminado queda como historial) --}}
                                                @if ($estadoBloqueo !== 'terminados')
                                                    <form method="POST" action="{{ route('bloqueos.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Levantar el bloqueo «' . $bloqueo->motivo . '»? Las fichas vuelven a estar disponibles, pero las citas canceladas no se restauran.') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $bloqueo->id }}">
                                                        <button type="submit" class="accion-peligro">Levantar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-8 text-center text-slate-500">
                                            {{ $hayFiltros ? 'No hay bloqueos con esos filtros.' : 'No hay bloqueos registrados.' }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$bloqueos" />
                </div>
            </div>

            {{-- CU25 pasos 3 a 6: formulario de registro --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                <section class="space-y-6">
                    <header>
                        <h2 class="tarjeta-titulo !mb-0">Nuevo bloqueo</h2>
                    </header>

                    @if ($errors->has('operacion') && $volvioFormulario)
                        <div class="alerta-error font-medium">{{ $errors->first('operacion') }}</div>
                    @endif

                    {{-- CU25 paso 6: resumen del primer envío y confirmación con los mismos datos --}}
                    @if ($resumen !== null)
                        <div class="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
                            <p class="font-semibold">
                                @if (count($resumen['citas']) > 0)
                                    Se bloquearán {{ $resumen['fichas'] }} fichas; {{ count($resumen['citas']) }} tienen cita y esas citas se cancelarán.
                                @else
                                    Se bloquearán {{ $resumen['fichas'] }} fichas; ninguna tiene cita.
                                @endif
                            </p>
                            @if (count($resumen['citas']) > 0)
                                <p>Se avisará a cada paciente (o a su responsable legal) y al médico.</p>
                                <ul class="max-h-64 space-y-1 overflow-y-auto">
                                    @foreach ($resumen['citas'] as $cita)
                                        <li>
                                            <span class="font-semibold">{{ $cita['codigo'] }}</span> — {{ $cita['paciente'] }}
                                            <span class="block text-xs">{{ $cita['medico'] }} · {{ $cita['fecha'] }} {{ $cita['hora'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif

                            <form method="POST" action="{{ route('bloqueos.guardar') }}" class="flex flex-wrap items-center gap-3 pt-1">
                                @csrf
                                @foreach ($camposFormulario as $campo)
                                    <input type="hidden" name="{{ $campo }}" value="{{ old($campo) }}">
                                @endforeach
                                <input type="hidden" name="confirmar" value="1">
                                <x-danger-button>Confirmar bloqueo</x-danger-button>
                                <span class="text-xs">o cambie los datos de abajo y vuelva a revisar.</span>
                            </form>
                        </div>
                    @endif

                    {{-- novalidate: la validación del navegador la hace validarBloqueo() (más abajo), en español --}}
                    <form method="POST" action="{{ route('bloqueos.guardar') }}" novalidate
                          @submit="if (! validarBloqueo($event.target)) $event.preventDefault()"
                          class="space-y-6">
                        @csrf

                        <div>
                            <x-input-label for="alcance" value="Alcance" />
                            <select id="alcance" name="alcance" x-model="alcance" class="campo">
                                <option value="">Elija…</option>
                                @foreach ($alcances as $valor)
                                    <option value="{{ $valor }}">{{ $nombresAlcance[$valor] }}</option>
                                @endforeach
                            </select>
                            <p class="js-error" data-campo="alcance" hidden></p>
                            <x-input-error :messages="$errors->get('alcance')" class="mt-2" />
                        </div>

                        {{-- ck_bloqueos_alcance_destino: médico solo si es MEDICO; consultorio solo si es CONSULTORIO --}}
                        <div x-show="alcance === 'MEDICO'" x-cloak>
                            <x-input-label for="medico_id" value="Médico" />
                            <select id="medico_id" name="medico_id" class="campo" :disabled="alcance !== 'MEDICO'">
                                <option value="">Elija…</option>
                                @foreach ($medicos as $medico)
                                    <option value="{{ $medico->id }}" @selected((string) old('medico_id') === (string) $medico->id)>
                                        {{ $medico->apellido_paterno }} {{ $medico->apellido_materno }}, {{ $medico->nombres }} ({{ $medico->codigo }})
                                    </option>
                                @endforeach
                            </select>
                            <p class="js-error" data-campo="medico_id" hidden></p>
                            <x-input-error :messages="$errors->get('medico_id')" class="mt-2" />
                        </div>

                        <div x-show="alcance === 'CONSULTORIO'" x-cloak>
                            <x-input-label for="consultorio_id" value="Consultorio" />
                            <select id="consultorio_id" name="consultorio_id" class="campo" :disabled="alcance !== 'CONSULTORIO'">
                                <option value="">Elija…</option>
                                @foreach ($consultorios as $consultorio)
                                    <option value="{{ $consultorio->id }}" @selected((string) old('consultorio_id') === (string) $consultorio->id)>
                                        {{ $consultorio->nombre }}{{ $consultorio->ubicacion ? ' (' . $consultorio->ubicacion . ')' : '' }}
                                    </option>
                                @endforeach
                            </select>
                            <p class="js-error" data-campo="consultorio_id" hidden></p>
                            <x-input-error :messages="$errors->get('consultorio_id')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="inicio" value="Inicio" />
                            <x-text-input id="inicio" name="inicio" type="datetime-local" :min="$minimo" class="mt-1 block w-full" :value="old('inicio')" />
                            <p class="js-error" data-campo="inicio" hidden></p>
                            <x-input-error :messages="$errors->get('inicio')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="fin" value="Fin" />
                            <x-text-input id="fin" name="fin" type="datetime-local" :min="$minimo" class="mt-1 block w-full" :value="old('fin')" />
                            <p class="mt-1 text-xs text-slate-500">El bloqueo termina a esa hora: para un día completo, use las 00:00 del día siguiente.</p>
                            <p class="js-error" data-campo="fin" hidden></p>
                            <x-input-error :messages="$errors->get('fin')" class="mt-2" />
                        </div>

                        <div>
                            <x-input-label for="motivo" value="Motivo" />
                            <textarea id="motivo" name="motivo" rows="3" maxlength="1000" class="campo">{{ old('motivo') }}</textarea>
                            <p class="js-error" data-campo="motivo" hidden></p>
                            <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <x-primary-button>Revisar bloqueo</x-primary-button>
                            <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>

    {{-- Validación en el cliente (CU25 pasos 4-5). El servidor vuelve a validar lo mismo. --}}
    <script>
        // Quita los mensajes de una validación anterior.
        function limpiarErroresJs(contenedor) {
            contenedor.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
        }

        function validarBloqueo(form) {
            limpiarErroresJs(form);
            const valor = (campo) => form.elements[campo].value.trim();
            const errores = {};
            const formato = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;

            if (valor('alcance') === '') {
                errores.alcance = 'Elija el alcance del bloqueo.';
            } else if (valor('alcance') === 'MEDICO' && valor('medico_id') === '') {
                errores.medico_id = 'Elija el médico.';
            } else if (valor('alcance') === 'CONSULTORIO' && valor('consultorio_id') === '') {
                errores.consultorio_id = 'Elija el consultorio.';
            }

            // Fecha y hora local con minutos, como la que envía el campo.
            const ahora = new Date();
            ahora.setSeconds(0, 0);
            if (valor('inicio') === '') {
                errores.inicio = 'El campo Inicio es obligatorio.';
            } else if (! formato.test(valor('inicio'))) {
                errores.inicio = 'El inicio no es una fecha y hora válida.';
            } else if (new Date(valor('inicio')) < ahora) {
                errores.inicio = 'El inicio no puede estar en el pasado.';
            }
            if (valor('fin') === '') {
                errores.fin = 'El campo Fin es obligatorio.';
            } else if (! formato.test(valor('fin'))) {
                errores.fin = 'El fin no es una fecha y hora válida.';
            } else if (! errores.inicio && valor('fin') <= valor('inicio')) {
                errores.fin = 'El fin debe ser posterior al inicio.';
            }
            if (valor('motivo') === '') {
                errores.motivo = 'El campo Motivo es obligatorio.';
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
