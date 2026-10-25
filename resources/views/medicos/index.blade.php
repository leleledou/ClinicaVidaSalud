{{-- CU07 Gestionar médicos (RF03): búsqueda, lista y formulario de registrar/modificar en la misma página. --}}
@php
    // Campos del formulario: si el servidor devolvió errores en alguno, se vuelve a abrir con lo escrito.
    $camposFormulario = ['tipo_documento', 'numero_documento', 'complemento', 'expedido', 'nombres', 'apellido_paterno', 'apellido_materno',
        'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion', 'registro_profesional', 'biografia', 'especialidades', 'especialidades.*', 'principal'];
    $volvioFormulario = $errors->hasAny($camposFormulario);

    $vacio = [
        'id' => null, 'tipo_documento' => 'CI', 'numero_documento' => '', 'complemento' => '', 'expedido' => '',
        'nombres' => '', 'apellido_paterno' => '', 'apellido_materno' => '', 'fecha_nacimiento' => '', 'sexo' => '',
        'telefono' => '', 'correo' => '', 'direccion' => '', 'registro_profesional' => '', 'biografia' => '',
        'especialidades' => [], 'principal' => '',
    ];

    $inicial = null;
    if ($volvioFormulario) {
        $inicial = array_merge($vacio, array_map(fn ($valor) => $valor ?? '', old()));
        $inicial['id'] = old('id');
        $inicial['especialidades'] = array_map('strval', (array) old('especialidades', []));
        // Al modificar, el documento no se envía: se muestra el de la persona.
        if (old('id') && $medicoEditado = $medicos->firstWhere('id', (int) old('id'))) {
            $inicial = array_merge($inicial, \Illuminate\Support\Arr::only($medicoEditado->persona->toArray(), ['tipo_documento', 'numero_documento', 'complemento', 'expedido']));
        }
    }

    // Datos de un médico para el botón Modificar.
    $datosEditar = function ($medico) {
        $principal = $medico->especialidades->first(fn ($especialidad) => $especialidad->pivot->es_principal);

        return array_merge(
            \Illuminate\Support\Arr::only($medico->persona->toArray(), ['tipo_documento', 'numero_documento', 'complemento', 'expedido', 'nombres',
                'apellido_paterno', 'apellido_materno', 'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion']),
            [
                'id' => $medico->id,
                'registro_profesional' => $medico->registro_profesional,
                'biografia' => $medico->biografia,
                'especialidades' => $medico->especialidades->map(fn ($especialidad) => (string) $especialidad->id)->values()->all(),
                'principal' => $principal ? (string) $principal->id : '',
            ]
        );
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Gestionar médicos
        </h2>
    </x-slot>

    <div class="py-12"
         x-data="formularioPersona({
            vacio: {{ Js::from($vacio) }},
            inicial: {{ Js::from($inicial) }},
            abierto: {{ Js::from($volvioFormulario) }},
            perfil: 'medico',
            edadMayoria: {{ $edadMayoria }},
            urlBuscar: {{ Js::from(route('personas.buscar')) }},
            urlFichaPaciente: '',
         })">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU07 A1 y otros errores de desactivar/activar (no son del formulario) --}}
            @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
                <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900 space-y-4">
                    {{-- RF03: buscar por nombre o especialidad --}}
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <form method="GET" action="{{ route('medicos.listar') }}" class="flex flex-wrap items-end gap-3">
                            <div>
                                <x-input-label for="q" value="Nombre o especialidad" />
                                <x-text-input id="q" name="q" type="search" class="mt-1 block w-64" :value="$texto" placeholder="Por ejemplo, Pérez o pediatría" />
                            </div>
                            <div>
                                <x-input-label for="especialidad_id" value="Especialidad" />
                                <select id="especialidad_id" name="especialidad_id" class="mt-1 block w-56 border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">
                                    <option value="">Todas</option>
                                    @foreach ($especialidades as $especialidad)
                                        <option value="{{ $especialidad->id }}" @selected($especialidadId === $especialidad->id)>{{ $especialidad->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-primary-button>Buscar</x-primary-button>
                            @if ($texto !== '' || $especialidadId)
                                <a href="{{ route('medicos.listar') }}" class="text-sm text-gray-600 hover:underline">Limpiar</a>
                            @endif
                        </form>

                        {{-- CU07 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo médico</x-primary-button>
                    </div>

                    {{-- CU07 paso 2: lista --}}
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Código</th>
                                    <th class="px-3 py-2">Nombre</th>
                                    <th class="px-3 py-2">Especialidades</th>
                                    <th class="px-3 py-2">Teléfono</th>
                                    <th class="px-3 py-2">Correo</th>
                                    <th class="px-3 py-2">Estado</th>
                                    <th class="px-3 py-2">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($medicos as $medico)
                                    <tr class="align-top">
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $medico->codigo }}</td>
                                        <td class="px-3 py-3">
                                            <a href="{{ route('medicos.mostrar', $medico->id) }}" class="font-medium text-indigo-600 hover:underline">
                                                {{ $medico->persona->nombre_completo }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-3">
                                            @forelse ($medico->especialidades as $especialidad)
                                                <span @class(['font-semibold' => $especialidad->pivot->es_principal])>
                                                    {{ $especialidad->nombre }}@if ($especialidad->pivot->es_principal) <span class="text-xs text-indigo-600">(principal)</span>@endif
                                                </span>@if (! $loop->last), @endif
                                            @empty
                                                <span class="text-gray-400">Sin especialidad activa</span>
                                            @endforelse
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $medico->persona->telefono }}</td>
                                        <td class="px-3 py-3">{{ $medico->persona->correo ?? '—' }}</td>
                                        <td class="px-3 py-3"><x-estado :estado="$medico->estado" /></td>
                                        <td class="px-3 py-3">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <x-secondary-button @click="editar({{ Js::from($datosEditar($medico)) }})">Modificar</x-secondary-button>

                                                @if ($medico->estado === 'ACTIVO')
                                                    {{-- CU07 A1: desactivar con confirmación; el id viaja oculto --}}
                                                    <form method="POST" action="{{ route('medicos.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar al médico '.$medico->persona->nombre_completo.' ('.$medico->codigo.')?') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $medico->id }}">
                                                        <x-danger-button>Desactivar</x-danger-button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('medicos.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $medico->id }}">
                                                        <x-primary-button>Activar</x-primary-button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-3 py-6 text-center text-gray-500">No se encontraron médicos.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$medicos" />
                </div>
            </div>

            {{-- CU07 pasos 4 a 6 / Modificar: formulario --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <section class="max-w-4xl space-y-6">
                    <header>
                        <h2 class="text-lg font-medium text-gray-900" x-text="form.id ? 'Modificar médico' : 'Nuevo médico'"></h2>
                        <p class="mt-1 text-sm text-gray-600" x-show="! form.id">Escriba el documento y pulse «Buscar»: si la persona ya está registrada, se reutilizan sus datos.</p>
                    </header>

                    {{-- novalidate: la validación la hace validarMedico() (resources/js/personas.js), en español --}}
                    <form method="POST" novalidate class="space-y-6"
                          :action="form.id ? {{ Js::from(route('medicos.modificar')) }} : {{ Js::from(route('medicos.guardar')) }}"
                          @submit="if (! validarMedico($event.target, {{ $edadMayoria }}, !! form.id)) $event.preventDefault()">
                        @csrf
                        {{-- El id viaja oculto solo al modificar --}}
                        <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                        @include('personas._campos', ['perfil' => 'medico', 'volvioFormulario' => $volvioFormulario])

                        {{-- CU07 paso 5: datos profesionales --}}
                        <fieldset class="space-y-4">
                            <legend class="text-sm font-semibold text-gray-700">Datos profesionales</legend>
                            <div>
                                <x-input-label for="registro_profesional" value="Registro profesional" />
                                <x-text-input id="registro_profesional" name="registro_profesional" type="text" maxlength="255" class="mt-1 block w-full sm:w-80" x-model="form.registro_profesional" placeholder="Por ejemplo, RP-MG-0104" />
                                <p class="js-error mt-2 text-sm text-red-600" data-campo="registro_profesional" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('registro_profesional')" class="mt-2" /> @endif
                            </div>
                            <div>
                                <x-input-label for="biografia" value="Biografía (opcional)" />
                                <textarea id="biografia" name="biografia" rows="3" maxlength="2000" x-model="form.biografia"
                                          class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm"></textarea>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('biografia')" class="mt-2" /> @endif
                            </div>
                        </fieldset>

                        {{-- CU07 paso 6: especialidades ACTIVAS y UNA principal --}}
                        <fieldset class="space-y-2">
                            <legend class="text-sm font-semibold text-gray-700">Especialidades (marque las que ejerce y elija la principal)</legend>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                @foreach ($especialidades as $especialidad)
                                    <div class="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="especialidades[]" value="{{ $especialidad->id }}" x-model="form.especialidades"
                                                   class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            <span>{{ $especialidad->nombre }}</span>
                                        </label>
                                        <label class="flex items-center gap-1 text-xs text-gray-600" :class="! form.especialidades.includes('{{ $especialidad->id }}') && 'opacity-40'">
                                            <input type="radio" name="principal" value="{{ $especialidad->id }}" x-model="form.principal"
                                                   :disabled="! form.especialidades.includes('{{ $especialidad->id }}')"
                                                   class="border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            Principal
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <p class="js-error mt-2 text-sm text-red-600" data-campo="especialidades" hidden></p>
                            @if ($volvioFormulario)
                                {{-- especialidades.* viene agrupado por casilla: se aplana --}}
                                <x-input-error :messages="collect($errors->get('especialidades'))->merge(collect($errors->get('especialidades.*'))->flatten())->merge($errors->get('principal'))->unique()->all()" class="mt-2" />
                            @endif
                            <p x-show="form.id" class="text-sm text-gray-600">
                                Una especialidad que se desmarca no se borra: queda inactiva y puede volver a marcarse.
                            </p>
                        </fieldset>

                        <div class="flex items-center gap-4">
                            <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                            <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                        </div>
                    </form>
                </section>
            </div>
        </div>
    </div>
</x-app-layout>
