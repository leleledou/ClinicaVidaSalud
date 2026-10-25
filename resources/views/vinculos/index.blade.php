{{-- CU13 Gestionar vínculos del paciente: datos del paciente, lista de vínculos y formulario en la misma página. --}}
@php
    $persona = $paciente->persona;
    $camposPersona = ['tipo_documento', 'numero_documento', 'complemento', 'expedido', 'nombres', 'apellido_paterno', 'apellido_materno',
        'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion'];
    $camposVinculo = ['parentesco', 'es_contacto_emergencia', 'es_responsable_legal', 'verificado', 'tipos'];
    // Si el servidor devolvió errores del formulario, se vuelve a abrir con lo escrito.
    $volvioFormulario = $errors->hasAny(array_merge($camposPersona, $camposVinculo));
    // Registrar: recepción con vinculos.gestionar o el propio paciente (A1).
    $puedeRegistrar = $puedeGestionar || $modo === 'paciente';

    $vacio = array_merge(['id' => null, 'persona_nombre' => ''], array_fill_keys($camposPersona, ''), [
        'tipo_documento' => 'CI', 'parentesco' => '', 'es_contacto_emergencia' => false, 'es_responsable_legal' => false, 'verificado' => false,
    ]);
    $inicial = $volvioFormulario
        ? array_merge($vacio, array_map(fn ($valor) => $valor ?? '', \Illuminate\Support\Arr::only(old(), $camposPersona)), [
            'id' => old('id'),
            'persona_nombre' => old('persona_nombre', ''),
            'parentesco' => old('parentesco', ''),
            'es_contacto_emergencia' => (bool) old('es_contacto_emergencia'),
            'es_responsable_legal' => (bool) old('es_responsable_legal'),
            'verificado' => (bool) old('verificado'),
        ])
        : null;

    // Datos de un vínculo para el botón Modificar (solo parentesco y tipos; la persona no cambia).
    $datosEditar = fn ($vinculo) => array_merge($vacio, [
        'id' => $vinculo->id,
        'persona_nombre' => $vinculo->persona->nombre_completo,
        'parentesco' => $vinculo->parentesco,
        'es_contacto_emergencia' => $vinculo->es_contacto_emergencia,
        'es_responsable_legal' => $vinculo->es_responsable_legal,
    ]);

    $documento = fn ($p) => $p->numero_documento
        ? $p->numero_documento . ($p->complemento ? '-' . $p->complemento : '')
        : 'Sin documento';

    // CU13 A2: el confirm advierte si es el único responsable legal verificado de un menor.
    $textoDesactivar = fn ($vinculo) => $vinculo->id === $unicoResponsableId
        ? 'Este es el único responsable legal verificado del paciente menor: al desactivarlo no podrá recibir citas nuevas (RN16). ¿Desactivar el vínculo con ' . $vinculo->persona->nombre_completo . '?'
        : '¿Desactivar el vínculo con ' . $vinculo->persona->nombre_completo . '?';
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Pacientes / Vínculos" :titulo="$modo === 'paciente' ? 'Mis contactos' : 'Vínculos del paciente'" />
    </x-slot>

    <div class="space-y-6"
         x-data="formularioPersona({
            vacio: {{ Js::from($vacio) }},
            inicial: {{ Js::from($inicial) }},
            abierto: {{ Js::from($volvioFormulario) }},
            perfil: 'vinculo',
            edadMayoria: {{ $edadMayoria }},
            urlBuscar: {{ Js::from(route('personas.buscar')) }},
            urlFichaPaciente: {{ Js::from(route('pacientes.mostrar', '__ID__')) }},
            pacienteId: {{ $paciente->id }},
         })">
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

            {{-- CU13 paso 2 / RN16: aviso del menor sin responsable legal verificado --}}
            @if ($sinResponsable)
                <div class="alerta-error font-medium">
                    Este paciente es menor de edad y no tiene un responsable legal verificado: no podrá recibir citas (RN16).
                </div>
            @endif

            {{-- CU13 paso 1: datos del paciente --}}
            <div class="tarjeta">
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="tarjeta-titulo !mb-0">Paciente</h2>
                    @if ($modo === 'recepcion' && Gate::allows('pacientes.buscar'))
                        <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="btn-secundario">Volver a la ficha</a>
                    @else
                        <a href="{{ route('panel') }}" class="btn-secundario">Volver al panel</a>
                    @endif
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 text-sm sm:grid-cols-3">
                    <div><dt class="text-slate-500">Código</dt><dd class="mt-1 font-medium text-marino-900">{{ $paciente->codigo }}</dd></div>
                    <div><dt class="text-slate-500">Paciente</dt><dd class="mt-1 font-semibold text-marino-900">{{ $persona->nombre_completo }}</dd></div>
                    <div>
                        <dt class="text-slate-500">Edad</dt>
                        <dd class="mt-1 font-medium text-marino-900">
                            {{ $persona->edad }} años
                            @if ($esMenor)
                                <span class="insignia insignia-inasistencia ms-1">Menor de edad</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            {{-- CU13 paso 2: lista de vínculos --}}
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Contactos de emergencia y responsables legales</h2>
                        @if ($puedeRegistrar)
                            <x-primary-button type="button" @click="nuevo()">Registrar vínculo</x-primary-button>
                        @endif
                    </div>

                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Documento</th>
                                    <th>Teléfono</th>
                                    <th>Parentesco</th>
                                    <th>Tipo</th>
                                    <th>Verificación</th>
                                    <th>Estado</th>
                                    @if ($puedeGestionar || $puedeVerificar)
                                        <th class="text-right">Acciones</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($vinculos as $vinculo)
                                    <tr class="align-top">
                                        <td class="font-semibold text-marino-900">{{ $vinculo->persona->nombre_completo }}</td>
                                        <td class="whitespace-nowrap">{{ $documento($vinculo->persona) }}</td>
                                        <td class="whitespace-nowrap">{{ $vinculo->persona->telefono }}</td>
                                        <td>{{ $vinculo->parentesco }}</td>
                                        <td>{{ $vinculo->tipoTexto() }}</td>
                                        <td>
                                            @if ($vinculo->fecha_verificacion)
                                                <span class="text-green-700">Verificado por {{ $vinculo->recepcionista?->persona->nombre_completo }} el {{ $vinculo->fecha_verificacion->format('d/m/Y') }}</span>
                                            @else
                                                <span class="text-amber-700">Pendiente de verificación</span>
                                            @endif
                                        </td>
                                        <td><x-estado :estado="$vinculo->estado" /></td>
                                        @if ($puedeGestionar || $puedeVerificar)
                                            <td>
                                                @if ($vinculo->estado === 'ACTIVO')
                                                    <div class="acciones">
                                                        {{-- CU13 Modificar: parentesco y tipos --}}
                                                        @if ($puedeGestionar)
                                                            <button type="button" class="accion" @click="editar({{ Js::from($datosEditar($vinculo)) }})">Modificar</button>
                                                        @endif

                                                        {{-- CU13 Verificar: el id viaja oculto --}}
                                                        @if ($puedeVerificar && ! $vinculo->fecha_verificacion)
                                                            <form method="POST" action="{{ route('vinculos-paciente.verificar') }}"
                                                                  onsubmit="return confirm({{ Js::from('¿Confirma que revisó el documento físico de '.$vinculo->persona->nombre_completo.'?') }});">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $vinculo->id }}">
                                                                <button type="submit" class="accion">Verificar</button>
                                                            </form>
                                                        @endif

                                                        {{-- CU13 A2: desactivar con confirmación; el id viaja oculto --}}
                                                        @if ($puedeGestionar)
                                                            <form method="POST" action="{{ route('vinculos-paciente.eliminar') }}"
                                                                  onsubmit="return confirm({{ Js::from($textoDesactivar($vinculo)) }});">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $vinculo->id }}">
                                                                <button type="submit" class="accion-peligro">Desactivar</button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="py-8 text-center text-slate-500">
                                            <p>El paciente aún no tiene vínculos registrados</p>
                                            @if ($puedeRegistrar)
                                                <x-primary-button type="button" class="mt-3" @click="nuevo()">Registrar vínculo</x-primary-button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- CU13 pasos 3 a 7 / Modificar: formulario de registro y modificación --}}
            @if ($puedeRegistrar)
                <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                    <section class="space-y-6">
                        <header class="space-y-3">
                            <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar vínculo' : 'Registrar vínculo'"></h2>
                            <p class="alerta-info" x-show="! form.id">Escriba el documento y pulse «Buscar»: si la persona ya está registrada, se reutilizan sus datos.</p>
                            <p class="text-sm text-slate-600" x-show="form.id">Persona vinculada: <span class="font-semibold text-marino-900" x-text="form.persona_nombre"></span> (la persona no se cambia).</p>
                            @if ($modo === 'paciente')
                                <p class="alerta-aviso">El vínculo quedará pendiente hasta que recepción revise el documento de la persona.</p>
                            @endif
                        </header>

                        {{-- novalidate: la validación la hace validarVinculo() (resources/js/personas.js), en español --}}
                        <form method="POST" novalidate class="space-y-6"
                              :action="form.id ? {{ Js::from(route('vinculos-paciente.modificar')) }} : {{ Js::from(route('vinculos-paciente.guardar')) }}"
                              @submit="if (! validarVinculo($event.target, {{ $edadMayoria }}, $data)) $event.preventDefault()">
                            @csrf
                            <input type="hidden" name="paciente_id" value="{{ $paciente->id }}">
                            {{-- El id viaja oculto solo al modificar --}}
                            <input type="hidden" name="id" :value="form.id" :disabled="! form.id">
                            <input type="hidden" name="persona_nombre" :value="form.persona_nombre" :disabled="! form.id">

                            {{-- CU13 paso 3: persona vinculada (solo al registrar) --}}
                            <div x-show="! form.id" class="space-y-6">
                                @include('personas._campos', ['perfil' => 'vinculo', 'volvioFormulario' => $volvioFormulario])
                            </div>

                            {{-- CU13 paso 4: parentesco y tipos --}}
                            <fieldset class="space-y-4">
                                <legend class="text-base font-semibold text-marino-900">Vínculo</legend>

                                <div>
                                    <x-input-label for="parentesco" value="Parentesco" />
                                    <select id="parentesco" name="parentesco" x-model="form.parentesco"
                                            class="campo">
                                        <option value="">Seleccione…</option>
                                        @foreach ($parentescos as $parentesco)
                                            <option value="{{ $parentesco }}">{{ $parentesco }}</option>
                                        @endforeach
                                    </select>
                                    <p class="js-error" data-campo="parentesco" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('parentesco')" class="mt-2" /> @endif
                                </div>

                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" name="es_contacto_emergencia" value="1" x-model="form.es_contacto_emergencia"
                                               class="casilla">
                                        Contacto de emergencia
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-slate-700">
                                        <input type="checkbox" name="es_responsable_legal" value="1" x-model="form.es_responsable_legal"
                                               class="casilla">
                                        Responsable legal (mayor de edad y con documento de identidad)
                                    </label>
                                    <p class="js-error" data-campo="tipos" hidden></p>
                                    <p class="js-error" data-campo="es_responsable_legal" hidden></p>
                                    @if ($volvioFormulario)
                                        <x-input-error :messages="array_merge($errors->get('tipos'), $errors->get('es_responsable_legal'), $errors->get('es_contacto_emergencia'))" />
                                    @endif
                                </div>

                                {{-- CU13 paso 5: solo para quien tiene vinculos.verificar y perfil de recepcionista ACTIVO --}}
                                @if ($puedeVerificar)
                                    <div x-show="! form.id" class="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700">
                                            <input type="checkbox" name="verificado" value="1" x-model="form.verificado" :disabled="form.id"
                                                   class="casilla">
                                            Verificado: revisé el documento físico
                                        </label>
                                        @if ($volvioFormulario) <x-input-error :messages="$errors->get('verificado')" class="mt-2" /> @endif
                                    </div>
                                @endif
                            </fieldset>

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
</x-app-layout>
