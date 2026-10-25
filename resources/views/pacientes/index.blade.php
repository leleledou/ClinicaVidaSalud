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
        <x-encabezado ruta="Pacientes / Buscar" titulo="Pacientes" />
    </x-slot>

    <div class="space-y-6"
         x-data="formularioPersona({
            vacio: {{ Js::from($vacio) }},
            inicial: {{ Js::from($inicial) }},
            abierto: {{ Js::from($volvioFormulario) }},
            perfil: 'paciente',
            edadMayoria: {{ $edadMayoria }},
            urlBuscar: {{ Js::from(route('personas.buscar')) }},
            urlFichaPaciente: {{ Js::from(route('pacientes.mostrar', '__ID__')) }},
         })">
        <div class="space-y-6">
            @if ($errors->hasAny(['operacion', 'id']) && ! $volvioFormulario)
                <div class="alerta-error font-medium">
                    <ul class="space-y-1">
                        @foreach (array_merge($errors->get('operacion'), $errors->get('id')) as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- CU11 pasos 1-2: un solo buscador --}}
            <div class="tarjeta">
                <h2 class="tarjeta-titulo">Filtros</h2>
                <form method="GET" action="{{ route('pacientes.listar') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <x-input-label for="q" value="Documento, código o nombre" />
                        <x-text-input id="q" name="q" type="search" :value="$texto" placeholder="Por ejemplo, 9910004, PAC-000004 o Flores" />
                    </div>
                    <div class="flex items-center gap-3">
                        <x-primary-button>Buscar</x-primary-button>
                        @if ($texto !== '')
                            <a href="{{ route('pacientes.listar') }}" class="btn-secundario">Limpiar</a>
                        @endif
                    </div>
                </form>
            </div>

            <div class="grid grid-cols-1 items-start gap-6 lg:grid-cols-3">
            <div class="tarjeta" :class="abierto ? 'lg:col-span-2' : 'lg:col-span-3'">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <h2 class="tarjeta-titulo !mb-0">Pacientes registrados</h2>
                        {{-- CU10 paso 1 --}}
                        @can('pacientes.gestionar')
                            <x-primary-button type="button" @click="nuevo()">Registrar paciente</x-primary-button>
                        @endcan
                    </div>

                    {{-- CU11 paso 4: lista --}}
                    <div class="tabla-contenedor">
                        <table class="tabla">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Documento</th>
                                    <th>Nombre</th>
                                    <th>Edad</th>
                                    <th>Teléfono</th>
                                    <th>Estado</th>
                                    @can('pacientes.gestionar')
                                        <th class="text-right">Acciones</th>
                                    @endcan
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($pacientes as $paciente)
                                    <tr class="align-top">
                                        {{-- CU11 paso 5: el código y el nombre abren la ficha --}}
                                        <td class="whitespace-nowrap">
                                            <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="font-semibold text-teal-700 hover:underline">{{ $paciente->codigo }}</a>
                                        </td>
                                        <td class="whitespace-nowrap">{{ $documento($paciente->persona) }}</td>
                                        <td>
                                            <a href="{{ route('pacientes.mostrar', $paciente->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">
                                                {{ $paciente->persona->nombre_completo }}
                                            </a>
                                        </td>
                                        <td class="whitespace-nowrap">{{ $paciente->persona->edad }} años</td>
                                        <td class="whitespace-nowrap">{{ $paciente->persona->telefono }}</td>
                                        {{-- CU11 A2: los inactivos aparecen con su etiqueta --}}
                                        <td><x-estado :estado="$paciente->estado" /></td>
                                        @can('pacientes.gestionar')
                                            <td>
                                                <div class="acciones">
                                                    {{-- CU10 A2 --}}
                                                    <button type="button" class="accion" @click="editar({{ Js::from($datosEditar($paciente)) }})">Modificar</button>

                                                    @if ($paciente->estado === 'ACTIVO')
                                                        {{-- CU10 A3: desactivar con confirmación; el id viaja oculto --}}
                                                        <form method="POST" action="{{ route('pacientes.eliminar') }}"
                                                              onsubmit="return confirm({{ Js::from('¿Desactivar al paciente '.$paciente->persona->nombre_completo.' ('.$paciente->codigo.')?') }});">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $paciente->id }}">
                                                            <button type="submit" class="accion-peligro">Desactivar</button>
                                                        </form>
                                                    @else
                                                        <form method="POST" action="{{ route('pacientes.activar') }}">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $paciente->id }}">
                                                            <button type="submit" class="accion">Activar</button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                        @endcan
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-slate-500">
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
                <div x-ref="formulario" x-show="abierto" x-cloak class="tarjeta lg:col-span-1">
                    <section class="space-y-6">
                        <header class="space-y-3">
                            <h2 class="tarjeta-titulo !mb-0" x-text="form.id ? 'Modificar paciente' : 'Registrar paciente'"></h2>
                            <p class="alerta-info" x-show="! form.id">Escriba el documento y pulse «Buscar»: si la persona ya está registrada, se reutilizan sus datos.</p>
                        </header>

                        {{-- novalidate: la validación la hace validarPaciente() (resources/js/personas.js), en español --}}
                        <form method="POST" novalidate class="space-y-6"
                              :action="form.id ? {{ Js::from(route('pacientes.modificar')) }} : {{ Js::from(route('pacientes.guardar')) }}"
                              @submit="if (! validarPaciente($event.target, {{ $edadMayoria }})) $event.preventDefault()">
                            @csrf
                            {{-- El id viaja oculto solo al modificar --}}
                            <input type="hidden" name="id" :value="form.id" :disabled="! form.id">

                            @include('personas._campos', ['perfil' => 'paciente', 'volvioFormulario' => $volvioFormulario])

                            <div class="flex flex-wrap items-center gap-3">
                                <x-primary-button x-text="form.id ? 'Guardar cambios' : 'Registrar'">Guardar</x-primary-button>
                                <x-secondary-button @click="abierto = false">Cancelar</x-secondary-button>
                            </div>
                        </form>
                    </section>
                </div>
            @endcan
            </div>
        </div>
    </div>
</x-app-layout>
