{{-- CU25 paso 8 Gestionar bloqueos de agenda (RF-12): datos del bloqueo, modificación del motivo (A1),
     levantar (A2), fichas afectadas y citas que canceló. --}}
@php
    $nombresAlcance = ['CLINICA' => 'Toda la clínica (feriado)', 'CONSULTORIO' => 'Consultorio (cierre)', 'MEDICO' => 'Médico (ausencia)'];
    $nombresSituacion = ['proximos' => 'Próximo', 'en_curso' => 'En curso', 'terminados' => 'Terminado'];
    $claseSituacion = ['proximos' => 'insignia-programada', 'en_curso' => 'insignia-inasistencia', 'terminados' => 'insignia-inactivo'];
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Catálogos / Bloqueos de agenda" :titulo="'Bloqueo #' . $bloqueo->id">
            <a href="{{ route('bloqueos.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        @if ($errors->hasAny(['operacion', 'id']))
            <div class="alerta-error font-medium">
                <ul class="space-y-1">
                    @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- Datos del bloqueo --}}
            <div class="tarjeta lg:col-span-2">
                <h2 class="tarjeta-titulo">Datos del bloqueo</h2>
                <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                    <div class="dato">
                        <dt>Alcance</dt>
                        <dd>{{ $nombresAlcance[$bloqueo->alcance] ?? $bloqueo->alcance }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Destino</dt>
                        <dd>{{ $bloqueo->destino() }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Inicio</dt>
                        <dd>{{ $bloqueo->inicio->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Fin</dt>
                        <dd>{{ $bloqueo->fin->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div class="dato">
                        <dt>Situación</dt>
                        <dd><span class="insignia {{ $claseSituacion[$situacion] }}">{{ $nombresSituacion[$situacion] }}</span></dd>
                    </div>
                    <div class="dato">
                        <dt>Citas canceladas</dt>
                        <dd>{{ $canceladas->count() }}</dd>
                    </div>
                    <div class="dato xl:col-span-2">
                        <dt>Motivo</dt>
                        <dd class="whitespace-pre-line">{{ $bloqueo->motivo }}</dd>
                    </div>
                </dl>

                {{-- CU25 A2: levantar (uno terminado queda como historial) --}}
                @if ($situacion !== 'terminados')
                    <form method="POST" action="{{ route('bloqueos.eliminar') }}" class="mt-6"
                          onsubmit="return confirm({{ Js::from('¿Levantar este bloqueo? Las fichas vuelven a estar disponibles, pero las citas canceladas no se restauran.') }});">
                        @csrf
                        <input type="hidden" name="id" value="{{ $bloqueo->id }}">
                        <x-danger-button>{{ $situacion === 'proximos' ? 'Levantar (eliminar) el bloqueo' : 'Levantar el bloqueo (termina ahora)' }}</x-danger-button>
                    </form>
                @endif
            </div>

            {{-- CU25 A1: solo se modifica el motivo --}}
            <div class="tarjeta lg:col-span-1">
                <h2 class="tarjeta-titulo">Modificar motivo</h2>
                <p class="alerta-info mb-4">Para cambiar fechas o alcance, levante este bloqueo y registre otro.</p>

                {{-- novalidate: la validación del navegador la hace validarMotivo() (más abajo), en español --}}
                <form method="POST" action="{{ route('bloqueos.modificar') }}" novalidate
                      onsubmit="return validarMotivo(this)" class="space-y-4">
                    @csrf
                    <input type="hidden" name="id" value="{{ $bloqueo->id }}">
                    <div>
                        <x-input-label for="motivo" value="Motivo" />
                        <textarea id="motivo" name="motivo" rows="4" maxlength="1000" class="campo">{{ old('motivo', $bloqueo->motivo) }}</textarea>
                        <p class="js-error" data-campo="motivo" hidden></p>
                        <x-input-error :messages="$errors->get('motivo')" class="mt-2" />
                    </div>
                    <x-primary-button>Guardar motivo</x-primary-button>
                </form>
            </div>
        </div>

        {{-- Citas canceladas por este bloqueo (no se restauran al levantarlo) --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Citas canceladas por este bloqueo</h2>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Paciente</th>
                            <th>Médico</th>
                            <th>Especialidad</th>
                            <th>Fecha y hora</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($canceladas as $cita)
                            @php $asignacion = $cita->ficha->horario->medicoEspecialidad; @endphp
                            <tr class="align-top">
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('citas.mostrar', $cita->id) }}" class="font-semibold text-teal-700 hover:underline">{{ $cita->codigo }}</a>
                                </td>
                                <td>{{ $cita->paciente->persona->nombre_completo }}</td>
                                <td>{{ $asignacion->medico->persona->nombre_completo }}</td>
                                <td>{{ $asignacion->especialidad->nombre }}</td>
                                <td class="whitespace-nowrap">
                                    {{ \Illuminate\Support\Carbon::parse($cita->ficha->fecha)->format('d/m/Y') }}
                                    {{ substr($cita->ficha->hora_inicio, 0, 5) }}–{{ substr($cita->ficha->hora_fin, 0, 5) }}
                                </td>
                                <td><x-estado-cita :estado="$cita->estado" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-500">Este bloqueo no canceló ninguna cita.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Fichas de su alcance que cruza el bloqueo --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Fichas afectadas</h2>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Médico</th>
                            <th>Especialidad</th>
                            <th>Consultorio</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($fichas as $ficha)
                            <tr>
                                <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($ficha->fecha)->format('d/m/Y') }}</td>
                                <td class="whitespace-nowrap">{{ substr($ficha->hora_inicio, 0, 5) }}–{{ substr($ficha->hora_fin, 0, 5) }}</td>
                                <td>{{ trim($ficha->nombres . ' ' . $ficha->apellido_paterno . ' ' . $ficha->apellido_materno) }}</td>
                                <td>{{ $ficha->especialidad }}</td>
                                <td>{{ $ficha->consultorio ?? 'Virtual' }}</td>
                                <td>
                                    @if ($ficha->estado === 'BLOQUEADA')
                                        <x-estado estado="BLOQUEADA" />
                                    @else
                                        <span class="insignia insignia-disponible">HABILITADA</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-500">No hay fichas generadas en el rango del bloqueo.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-paginacion :paginador="$fichas" />
        </div>
    </div>

    {{-- Validación en el cliente (CU25 A1). El servidor vuelve a validar lo mismo. --}}
    <script>
        function validarMotivo(form) {
            const p = form.querySelector('.js-error[data-campo="motivo"]');
            const vacio = form.elements['motivo'].value.trim() === '';
            p.textContent = vacio ? 'El campo Motivo es obligatorio.' : '';
            p.hidden = ! vacio;
            return ! vacio;
        }
    </script>
</x-app-layout>
