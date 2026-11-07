{{-- CU21 Consultar bitácora (RF-06): filtros y lista paginada, de la más reciente a la más antigua.
     Solo lectura: no hay registrar, modificar ni eliminar. --}}
@php
    $hayFiltros = collect($filtros)->filter()->isNotEmpty();
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Seguridad / Bitácora" titulo="Bitácora" />
    </x-slot>

    <div class="space-y-6">
        {{-- CU21 paso 2: filtros combinables (se conservan en la paginación) --}}
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Filtros</h2>
            <form method="GET" action="{{ route('bitacora.listar') }}" class="grid grid-cols-1 items-start gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <x-input-label for="desde" value="Desde" />
                    <x-text-input id="desde" name="desde" type="date" class="mt-1 block w-full" :value="$filtros['desde']" />
                    <x-input-error :messages="$errores->get('desde')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="hasta" value="Hasta" />
                    <x-text-input id="hasta" name="hasta" type="date" class="mt-1 block w-full" :value="$filtros['hasta']" />
                    <x-input-error :messages="$errores->get('hasta')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="usuario" value="Usuario (correo o nombre)" />
                    <x-text-input id="usuario" name="usuario" type="search" class="mt-1 block w-full" :value="$filtros['usuario']" maxlength="255" placeholder="Por ejemplo, admin@ o Flores" />
                    <x-input-error :messages="$errores->get('usuario')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="modulo" value="Módulo" />
                    <select id="modulo" name="modulo" class="campo">
                        <option value="">Todos</option>
                        @foreach ($modulos as $valor)
                            <option value="{{ $valor }}" @selected($filtros['modulo'] === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errores->get('modulo')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="accion" value="Acción" />
                    <x-text-input id="accion" name="accion" type="search" class="mt-1 block w-full" :value="$filtros['accion']" maxlength="255" placeholder="Por ejemplo, LOGIN" />
                    <x-input-error :messages="$errores->get('accion')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="canal" value="Canal" />
                    <select id="canal" name="canal" class="campo">
                        <option value="">Todos</option>
                        @foreach ($canales as $valor)
                            <option value="{{ $valor }}" @selected($filtros['canal'] === $valor)>{{ $valor }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errores->get('canal')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="resultado" value="Resultado" />
                    <select id="resultado" name="resultado" class="campo">
                        <option value="">Todos</option>
                        <option value="exito" @selected($filtros['resultado'] === 'exito')>Éxito</option>
                        <option value="fallido" @selected($filtros['resultado'] === 'fallido')>Fallido</option>
                    </select>
                    <x-input-error :messages="$errores->get('resultado')" class="mt-2" />
                </div>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-2 lg:col-span-4">
                    <x-primary-button>Filtrar</x-primary-button>
                    @if ($hayFiltros || $errores->isNotEmpty())
                        <a href="{{ route('bitacora.listar') }}" class="btn-secundario">Limpiar</a>
                    @endif
                </div>
            </form>
        </div>

        {{-- CU21 paso 3: lista --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Registros</h2>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Fecha y hora</th>
                            <th>Usuario</th>
                            <th>Módulo</th>
                            <th>Acción</th>
                            <th>Registro afectado</th>
                            <th>Canal</th>
                            <th>Resultado</th>
                            <th>IP</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($registros as $registro)
                            <tr class="align-top">
                                <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($registro->fecha_hora)->format('d/m/Y H:i:s') }}</td>
                                <td>
                                    {{-- Las operaciones automáticas no tienen usuario --}}
                                    @if ($registro->usuario)
                                        <span class="font-semibold text-marino-900">{{ $registro->usuario->persona?->nombre_completo ?? $registro->usuario->nombre_usuario }}</span>
                                        <span class="block text-xs text-slate-500">{{ $registro->usuario->correo }}</span>
                                    @else
                                        <span class="text-slate-500">Sistema</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">{{ $registro->modulo }}</td>
                                <td class="whitespace-nowrap font-medium">{{ $registro->accion }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($registro->tabla_afectada)
                                        {{ $registro->tabla_afectada }}{{ $registro->registro_id ? ' #' . $registro->registro_id : '' }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="whitespace-nowrap">{{ $registro->canal }}</td>
                                <td>
                                    <span @class(['insignia', 'insignia-activo' => $registro->exito, 'insignia-cancelada' => ! $registro->exito])>
                                        {{ $registro->exito ? 'Éxito' : 'Fallido' }}
                                    </span>
                                </td>
                                <td class="whitespace-nowrap text-slate-600">{{ $registro->ip ?? '—' }}</td>
                                <td>
                                    <div class="acciones">
                                        {{-- CU21 paso 4 --}}
                                        <a href="{{ route('bitacora.mostrar', $registro->id) }}" class="accion">Ver</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-8 text-center text-slate-500">
                                    {{ $hayFiltros ? 'No hay registros que coincidan con los filtros.' : 'La bitácora no tiene registros.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-paginacion :paginador="$registros" />
        </div>
    </div>
</x-app-layout>
