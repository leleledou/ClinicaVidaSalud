{{-- CU11 Buscar paciente y CU10 Gestionar pacientes (RF02): buscador, lista y formulario en la misma página. --}}
@php
    $camposPersona = ['tipo_documento', 'numero_documento', 'complemento', 'expedido', 'nombres', 'apellido_paterno', 'apellido_materno',
        'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion'];
    // Si el servidor devolvió errores del formulario, se vuelve a abrir con lo escrito.
    $volvioFormulario = $errors->hasAny($camposPersona);

    $vacio = array_merge(['id' => null], array_fill_keys($camposPersona, ''), ['tipo_documento' => 'CI']);
    $inicial = $volvioFormulario
        ? array_merge($vacio, array_map(fn ($valor) => $valor ?? '', \Illuminate\Support\Arr::only(old(), $camposPersona)), ['id' => old('id')])
        : null;

    // Datos de un paciente para el botón Modificar (la fecha sale como AAAA-MM-DD).
    $datosEditar = fn ($paciente) => array_merge(\Illuminate\Support\Arr::only($paciente->persona->toArray(), $camposPersona), ['id' => $paciente->id]);

    $documento = fn ($persona) => $persona->numero_documento
        ? $persona->numero_documento . ($persona->complemento ? '-' . $persona->complemento : '')
        : 'Sin documento';
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pacientes
        </h2>
    </x-slot>

    <div class="py-12"
         x-data="formularioPersona({
            vacio: {{ Js::from($vacio) }},
            inicial: {{ Js::from($inicial) }},
            abierto: {{ Js::from($volvioFormulario) }},
            perfil: 'paciente',
            edadMayoria: {{ $edadMayoria }},
            urlBuscar: {{ Js::from(route('personas.buscar')) }},
            urlFichaPaciente: {{ Js::from(route('pacientes.mostrar', '__ID__')) }},
         })">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
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
                    {{-- CU11 pasos 1-2: un solo buscador --}}
                    <div class="flex flex-wrap items-end justify-between gap-4">
                        <form method="GET" action="{{ route('pacientes.listar') }}" class="flex flex-wrap items-end gap-3">
                            <div>
                                <x-input-label for="q" value="Documento, código o nombre" />
                                <x-text-input id="q" name="q" type="search" class="mt-1 block w-80" :value="$texto" placeholder="Por ejemplo, 9910004, PAC-000004 o Flores" />
                            </div>
                            <x-primary-button>Buscar</x-primary-button>
                            @if ($texto !== '')
                                <a href="{{ route('pacientes.listar') }}" class="text-sm text-gray-600 hover:underline">Limpiar</a>
                            @endif
                        </form>

                        {{-- CU10 paso 1 --}}
                        @can('pacientes.gestionar')
                            <x-primary-button type="button" @click="nuevo()">Registrar paciente</x-primary-button>
                        @endcan
                    </div>

                    {{-- CU11 paso 4: lista --}}
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-gray-500">
                                    <th class="px-3 py-2">Código</th>
                                    <th class="px-3 py-2">Documento</th>
                                    <th class="px-3 py-2">Nombre</th>
                                    <th class="px-3 py-2">Edad</th>
                                    <th class="px-3 py-2">Teléfono</th>
                                    <th class="px-3 py-2">Estado</th>
                                    @can('pacientes.gestionar')
                                        <th class="px-3 py-2">Acciones</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($pacientes as $paciente)
                                    <tr class="align-top">
                                        {{-- CU11 paso 5: el código y el nombre abren la ficha --}}
                                        <td class="px-3 py-3 whitespace-nowrap">
                                            <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="text-indigo-600 hover:underline">{{ $paciente->codigo }}</a>
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $documento($paciente->persona) }}</td>
                                        <td class="px-3 py-3">
                                            <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="font-medium text-indigo-600 hover:underline">
                                                {{ $paciente->persona->nombre_completo }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $paciente->persona->edad }} años</td>
                                        <td class="px-3 py-3 whitespace-nowrap">{{ $paciente->persona->telefono }}</td>
                                        {{-- CU11 A2: los inactivos aparecen con su etiqueta --}}
                                        <td class="px-3 py-3"><x-estado :estado="$paciente->estado" /></td>
                                        @can('pacientes.gestionar')
                                            <td class="px-3 py-3">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    {{-- CU10 A2 --}}
                                                    <x-secondary-button @click="editar({{ Js::from($datosEditar($paciente)) }})">Modificar</x-secondary-button>

                                                    @if ($paciente->estado === 'ACTIVO')
                                                        {{-- CU10 A3: desactivar con confirmación; el id viaja oculto --}}
                                                        <form method="POST" action="{{ route('pacientes.eliminar') }}"
                                                              onsubmit="return confirm({{ Js::from('¿Desactivar al paciente '.$paciente->persona->nombre_completo.' ('.$paciente->codigo.')?') }});">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $paciente->id }}">
                                                            <x-danger-button>Desactivar</x-danger-button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('pacientes.activar') }}">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $paciente->id }}">
                                                            <x-primary-button>Activar</x-primary-button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        @endcan
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="px-3 py-6 text-center text-gray-500">
                                            <p>No se encontraron pacientes</p>
                                            @can('pacientes.gestionar')
                                                <x-primary-button type="button" class="mt-3" @click="nuevo()">Registrar paciente</x-primary-button>
                                            @endcan
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <x-paginacion :paginador="$pacientes" />
                </div>
            </div>

            {{-- CU10 pasos 2 a 6 / A2: formulario de registro y modificación --}}
            @can('pacientes.gestionar')
                <div x-ref="formulario" x-show="abierto" x-cloak class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <section class="max-w-4xl space-y-6">
                        <header>
                            <h2 class="text-lg font-medium text-gray-900" x-text="form.id ? 'Modificar paciente' : 'Registrar paciente'"></h2>
                            <p class="mt-1 text-sm text-gray-600" x-show="! form.id">Escriba el documento y pulse «Buscar»: si la persona ya está registrada, se reutilizan sus datos.</p>
                        </header>

                        {{-- novalidate: la validación la hace validarPaciente() (resources/js/personas.js), en español --}}
                        <form method="POST" novalidate class="space-y-6"
                              :action="form.id ? {{ Js::from(route('pacientes.modificar')) }} : {{ Js::from(route('pacientes.guardar')) }}"
                              @submit="if (! validarPaciente($event.target, {{ $edadMayoria }})) $event.preventDefault()">
                            @csrf
                            {{-- El id viaja oculto solo al modificar --}}
                            <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                            @include('personas._campos', ['perfil' => 'paciente', 'volvioFormulario' => $volvioFormulario])

                            <div class="flex items-center gap-4">
                                <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                                <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                            </div>
                        </form>
                    </section>
                </div>
            @endcan
        </div>
    </div>
</x-app-layout>
