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
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                {{ $modo === 'paciente' ? 'Mis contactos' : 'Vínculos del paciente' }}
            </h2>
            @if ($modo === 'recepcion' && Gate::allows('pacientes.buscar'))
                <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="text-sm text-indigo-600 hover:underline">Volver a la ficha</a>
            @else
                <a href="{{ route('panel') }}" class="text-sm text-indigo-600 hover:underline">Volver al panel</a>
            @endif
        </div>
    </x-slot>

    <div class="py-12"
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
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if ($errors->hasAny(['operacion', 'id', 'paciente_id']) && ! $volvioFormulario)
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id'), $errors->get('paciente_id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- CU13 paso 2 / RN16: aviso del menor sin responsable legal verificado --}}
            @if ($sinResponsable)
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    Este paciente es menor de edad y no tiene un responsable legal verificado: no podrá recibir citas (RN16).
                </div>
            @endif

            {{-- CU13 paso 1: datos del paciente --}}
            <div class="bg-white shadow-sm sm:rounded-lg p-6">
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4 text-sm">
                    <div><dt class="font-medium text-gray-500">Código</dt><dd class="mt-1">{{ $paciente->codigo }}</dd></div>
                    <div><dt class="font-medium text-gray-500">Paciente</dt><dd class="mt-1 font-medium">{{ $persona->nombre_completo }}</dd></div>
                    <div>
                        <dt class="font-medium text-gray-500">Edad</dt>
                        <dd class="mt-1">
                            {{ $persona->edad }} años
                            @if ($esMenor)
                                <span class="ms-1 rounded-full bg-yellow-100 px-2 py-0.5 text-xs font-semibold text-yellow-800">Menor de edad</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- CU13 paso 2: lista de vínculos --}}
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <h3 class="text-lg font-medium text-gray-900">Contactos de emergencia y responsables legales</h3>
                        @if ($puedeRegistrar)
                            <x-primary-button type="button" @click="nuevo()">Registrar vínculo</x-primary-button>
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Nombre</th>
                                    <th class="px-3 py-2">Documento</th>
                                    <th class="px-3 py-2">Teléfono</th>
                                    <th class="px-3 py-2">Parentesco</th>
                                    <th class="px-3 py-2">Tipo</th>
                                    <th class="px-3 py-2">Verificación</th>
                                    <th class="px-3 py-2">Estado</th>
                                    @if ($puedeGestionar || $puedeVerificar)
                                        <th class="px-3 py-2">Acciones</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($vinculos as $vinculo)
                                    <tr class="align-top">
                                        <td class="px-3 py-3 font-medium">{{ $vinculo->persona->nombre_completo }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $documento($vinculo->persona) }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $vinculo->persona->telefono }}</td>
                                        <td class="px-3 py-3">{{ $vinculo->parentesco }}</td>
                                        <td class="px-3 py-3">{{ $vinculo->tipoTexto() }}</td>
                                        <td class="px-3 py-3">
                                            @if ($vinculo->fecha_verificacion)
                                                <span class="text-green-700">Verificado por {{ $vinculo->recepcionista?->persona->nombre_completo }} el {{ $vinculo->fecha_verificacion->format('d/m/Y') }}</span>
                                            @else
                                                <span class="text-yellow-700">Pendiente de verificación</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3"><x-estado :estado="$vinculo->estado" /></td>
                                        @if ($puedeGestionar || $puedeVerificar)
                                            <td class="px-3 py-3">
                                                @if ($vinculo->estado === 'ACTIVO')
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        {{-- CU13 Modificar: parentesco y tipos --}}
                                                        @if ($puedeGestionar)
                                                            <x-secondary-button @click="editar({{ Js::from($datosEditar($vinculo)) }})">Modificar</x-secondary-button>
                                                        @endif

                                                        {{-- CU13 Verificar: el id viaja oculto --}}
                                                        @if ($puedeVerificar && ! $vinculo->fecha_verificacion)
                                                            <form method="POST" action="{{ route('vinculos-paciente.verificar') }}"
                                                                  onsubmit="return confirm({{ Js::from('¿Confirma que revisó el documento físico de '.$vinculo->persona->nombre_completo.'?') }});">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $vinculo->id }}">
                                                                <x-primary-button>Verificar</x-primary-button>
                                                            </form>
                                                        @endif

                                                        {{-- CU13 A2: desactivar con confirmación; el id viaja oculto --}}
                                                        @if ($puedeGestionar)
                                                            <form method="POST" action="{{ route('vinculos-paciente.eliminar') }}"
                                                                  onsubmit="return confirm({{ Js::from($textoDesactivar($vinculo)) }});">
                                                                @csrf
                                                                <input type="hidden" name="id" value="{{ $vinculo->id }}">
                                                                <x-danger-button>Desactivar</x-danger-button>
                                                            </form>
                                                        @endif
                                                    </div>
                                                @endif
                                            </td>
                                        @endif
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="8" class="px-3 py-6 text-center text-gray-500">
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
                <div x-ref="formulario" x-show="abierto" x-cloak class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <section class="max-w-4xl space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900" x-text="form.id ? 'Modificar vínculo' : 'Registrar vínculo'"></h2>
                            <p class="mt-1 text-sm text-gray-600" x-show="! form.id">Escriba el documento y pulse «Buscar»: si la persona ya está registrada, se reutilizan sus datos.</p>
                            <p class="mt-1 text-sm text-gray-600" x-show="form.id">Persona vinculada: <span class="font-medium" x-text="form.persona_nombre"></span> (la persona no se cambia).</p>
                            @if ($modo === 'paciente')
                                <p class="mt-1 text-sm text-gray-600">El vínculo quedará pendiente hasta que recepción revise el documento de la persona.</p>
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
                                <legend class="text-sm font-semibold text-gray-700">Vínculo</legend>

                                <div class="max-w-xs">
                                    <x-input-label for="parentesco" value="Parentesco" />
                                    <select id="parentesco" name="parentesco" x-model="form.parentesco"
                                            class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                        <option value="">Seleccione…</option>
                                        @foreach ($parentescos as $parentesco)
                                            <option value="{{ $parentesco }}">{{ $parentesco }}</option>
                                        @endforeach
                                    </select>
                                    <p class="js-error mt-2 text-sm text-red-600" data-campo="parentesco" hidden></p>
                                    @if ($volvioFormulario) <x-input-error :messages="$errors->get('parentesco')" class="mt-2" /> @endif
                                </div>

                                <div class="space-y-2">
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="es_contacto_emergencia" value="1" x-model="form.es_contacto_emergencia"
                                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                        Contacto de emergencia
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-700">
                                        <input type="checkbox" name="es_responsable_legal" value="1" x-model="form.es_responsable_legal"
                                               class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                        Responsable legal (mayor de edad y con documento de identidad)
                                    </label>
                                    <p class="js-error text-sm text-red-600" data-campo="tipos" hidden></p>
                                    <p class="js-error text-sm text-red-600" data-campo="es_responsable_legal" hidden></p>
                                    @if ($volvioFormulario)
                                        <x-input-error :messages="array_merge($errors->get('tipos'), $errors->get('es_responsable_legal'), $errors->get('es_contacto_emergencia'))" />
                                    @endif
                                </div>

                                {{-- CU13 paso 5: solo para quien tiene vinculos.verificar y perfil de recepcionista ACTIVO --}}
                                @if ($puedeVerificar)
                                    <div x-show="! form.id" class="rounded-md bg-gray-50 p-3">
                                        <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                                            <input type="checkbox" name="verificado" value="1" x-model="form.verificado" :disabled="form.id"
                                                   class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500">
                                            Verificado: revisé el documento físico
                                        </label>
                                        @if ($volvioFormulario) <x-input-error :messages="$errors->get('verificado')" class="mt-2" /> @endif
                                    </div>
                                @endif
                            </fieldset>

                            <div class="flex items-center gap-4">
                                <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                                <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                            </div>
                        </form>
                    </section>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
