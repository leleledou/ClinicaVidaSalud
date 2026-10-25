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
        <x-encabezado ruta="Catálogos / Médicos" titulo="Gestionar médicos" />
    </x-slot>

    <div class="space-y-6"
         x-data="formularioPersona({
            vacio: {{ Js::from($vacio) }},
            inicial: {{ Js::from($inicial) }},
            abierto: {{ Js::from($volvioFormulario) }},
            perfil: 'medico',
            edadMayoria: {{ $edadMayoria }},
            urlBuscar: {{ Js::from(route('personas.buscar')) }},
            urlFichaPaciente: '',
         })">
        <div class="space-y-6">
            {{-- CU07 A1 y otros errores de desactivar/activar (no son del formulario) --}}
            @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
                <div class="alerta-error font-medium">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- RF03: buscar por nombre o especialidad --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Filtros</h2>
                <form method="GET" action="{{ route('medicos.listar') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div>
                        <x-input-label for="q" value="Nombre o especialidad" />
                        <x-text-input id="q" name="q" type="search" :value="$texto" placeholder="Por ejemplo, Pérez o pediatría" />
                    </div>
                    <div>
                        <x-input-label for="especialidad_id" value="Especialidad" />
                        <select id="especialidad_id" name="especialidad_id" class="campo">
                            <option value="">Todas</option>
                            @foreach ($especialidades as $especialidad)
                                <option value="{{ $especialidad->id }}" @selected($especialidadId === $especialidad->id)>{{ $especialidad->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button>Buscar</x-primary-button>
                        @if ($texto !== '' || $especialidadId)
                            <a href="{{ route('medicos.listar') }}" class="btn-secundario">Limpiar</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Médicos registrados</h2>
                        {{-- CU07 paso 3 --}}
                        <x-primary-button type="button" @click="nuevo()">Nuevo médico</x-primary-button>
                    </div>

                    {{-- CU07 paso 2: lista --}}
                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Especialidades</th>
                                    <th>Teléfono</th>
                                    <th>Correo</th>
                                    <th>Estado</th>
                                    <th class="text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($medicos as $medico)
                                    <tr class="align-top">
                                        <td class="whitespace-nowrap">{{ $medico->codigo }}</td>
                                        <td>
                                            <a href="{{ route('medicos.mostrar', $medico->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">
                                                {{ $medico->persona->nombre_completo }}
                                            </a>
                                        </td>
                                        <td>
                                            @forelse ($medico->especialidades as $especialidad)
                                                <span @class(['font-semibold' => $especialidad->pivot->es_principal])>
                                                    {{ $especialidad->nombre }}@if ($especialidad->pivot->es_principal) <span class="text-xs text-teal-700">(principal)</span>@endif
                                                </span>@if (! $loop->last), @endif
                                            @empty
                                                <span class="text-slate-400">Sin especialidad activa</span>
                                            @endforelse
                                        </td>
                                        <td class="whitespace-nowrap">{{ $medico->persona->telefono }}</td>
                                        <td>{{ $medico->persona->correo ?? '—' }}</td>
                                        <td><x-estado :estado="$medico->estado" /></td>
                                        <td>
                                            <div class="acciones">
                                                <button type="button" class="accion" @click="editar({{ Js::from($datosEditar($medico)) }})">Modificar</button>

                                                @if ($medico->estado === 'ACTIVO')
                                                    {{-- CU07 A1: desactivar con confirmación; el id viaja oculto --}}
                                                    <form method="POST" action="{{ route('medicos.eliminar') }}"
                                                          onsubmit="return confirm({{ Js::from('¿Desactivar al médico '.$medico->persona->nombre_completo.' ('.$medico->codigo.')?') }});">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $medico->id }}">
                                                        <button type="submit" class="accion-peligro">Desactivar</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('medicos.activar') }}">
                                                        @csrf
                                                        <input type="hidden" name="id" value="{{ $medico->id }}">
                                                        <button type="submit" class="accion">Activar</button>
                                                    </form>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-slate-500">No se encontraron médicos.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$medicos" />
                </div>
            </div>

            {{-- CU07 pasos 4 a 6 / Modificar: formulario --}}
            <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                <section class="space-y-6">
                    <header class="space-y-3">
                        <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar médico' : 'Nuevo médico'"></h2>
                        <p class="alerta-info" x-show="! form.id">Escriba el documento y pulse «Buscar»: si la persona ya está registrada, se reutilizan sus datos.</p>
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
                            <legend class="text-base font-semibold text-marino-900">Datos profesionales</legend>
                            <div>
                                <x-input-label for="registro_profesional" value="Registro profesional" />
                                <x-text-input id="registro_profesional" name="registro_profesional" type="text" maxlength="255" x-model="form.registro_profesional" placeholder="Por ejemplo, RP-MG-0104" />
                                <p class="js-error" data-campo="registro_profesional" hidden></p>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('registro_profesional')" class="mt-2" /> @endif
                            </div>
                            <div>
                                <x-input-label for="biografia" value="Biografía (opcional)" />
                                <textarea id="biografia" name="biografia" rows="3" maxlength="2000" x-model="form.biografia"
                                          class="campo"></textarea>
                                @if ($volvioFormulario) <x-input-error :messages="$errors->get('biografia')" class="mt-2" /> @endif
                            </div>
                        </fieldset>

                        {{-- CU07 paso 6: especialidades ACTIVAS y UNA principal --}}
                        <fieldset class="space-y-2">
                            <legend class="text-base font-semibold text-marino-900">Especialidades (marque las que ejerce y elija la principal)</legend>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2">
                                @foreach ($especialidades as $especialidad)
                                    <div class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm">
                                        <label class="flex items-center gap-2">
                                            <input type="checkbox" name="especialidades[]" value="{{ $especialidad->id }}" x-model="form.especialidades"
                                                   class="rounded border-slate-300 text-teal-700 focus:ring-teal-500">
                                            <span>{{ $especialidad->nombre }}</span>
                                        </label>
                                        <label class="flex items-center gap-1 text-xs text-slate-600" :class="! form.especialidades.includes('{{ $especialidad->id }}') && 'opacity-40'">
                                            <input type="radio" name="principal" value="{{ $especialidad->id }}" x-model="form.principal"
                                                   :disabled="! form.especialidades.includes('{{ $especialidad->id }}')"
                                                   class="border-slate-300 text-teal-700 focus:ring-teal-500">
                                            Principal
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                            <p class="js-error" data-campo="especialidades" hidden></p>
                            @if ($volvioFormulario)
                                {{-- especialidades.* viene agrupado por casilla: se aplana --}}
                                <x-input-error :messages="collect($errors->get('especialidades'))->merge(collect($errors->get('especialidades.*'))->flatten())->merge($errors->get('principal'))->unique()->all()" class="mt-2" />
                            @endif
                            <p x-show="form.id" class="alerta-info">
                                Una especialidad que se desmarca no se borra: queda inactiva y puede volver a marcarse.
                            </p>
                        </fieldset>

                        <div class="flex flex-wrap items-center gap-3">
                            <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                            <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                        </div>
                    </form>
                </section>
            </div>
            </div>
        </div>
    </div>
</x-app-layout>
