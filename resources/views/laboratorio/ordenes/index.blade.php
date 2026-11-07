{{-- CU35 Registrar resultados de laboratorio (RF-36) paso 1 y CU36 Revisar resultados y evolución (RF-37) paso 1:
     lista paginada de las órdenes que el usuario puede ver (OrdenLaboratorio::alcance), con filtro de estado y texto.
     Laboratorio: por defecto las pendientes, primero URGENTE y luego la más antigua. El paciente la ve como «Mis resultados». --}}
@php
    $hayFiltros = $texto !== '' || request()->filled('estado');
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Laboratorio / Órdenes" :titulo="$titulo" />
    </x-slot>

    <div class="space-y-6">
        {{-- CU35 paso 1 / CU36 paso 1: código de la orden o nombre o código del paciente, y estado --}}
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Filtros</h2>
            <form method="GET" action="{{ route('ordenes-laboratorio.listar') }}" class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="lg:col-span-2">
                    <x-input-label for="q" value="Orden o paciente" />
                    <x-text-input id="q" name="q" type="search" :value="$texto" placeholder="Por ejemplo, LAB-000554 o PAC-000001 o Pedro" />
                </div>
                <div>
                    <x-input-label for="estado" value="Estado" />
                    <select id="estado" name="estado" class="campo">
                        @foreach ($filtros as $valor => $nombre)
                            <option value="{{ $valor }}" @selected($estado === $valor)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <x-primary-button>Buscar</x-primary-button>
                    @if ($hayFiltros)
                        <a href="{{ route('ordenes-laboratorio.listar') }}" class="btn-secundario">Limpiar</a>
                    @endif
                </div>
            </form>
        </div>

        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo !mb-0">{{ $titulo }} ({{ $ordenes->total() }})</h2>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Paciente</th>
                            <th>Examen</th>
                            <th>Médico solicitante</th>
                            <th>Prioridad</th>
                            <th>Estado</th>
                            <th>Solicitada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ordenes as $orden)
                            @php
                                $cita = $orden->consulta->cita;
                                $medico = $cita->medico()?->persona;
                            @endphp
                            <tr class="align-top">
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('ordenes-laboratorio.mostrar', $orden->id) }}" class="font-semibold text-marino-900 hover:text-teal-700 hover:underline">
                                        {{ $orden->codigo }}
                                    </a>
                                </td>
                                <td>{{ $cita->paciente->persona->nombre_completo }} <span class="text-slate-500">({{ $cita->paciente->codigo }})</span></td>
                                <td>{{ $orden->tipoExamen->nombre }}</td>
                                <td>{{ $medico ? \App\Services\CitaService::tituloMedico($medico) : '—' }}</td>
                                <td>
                                    <span class="insignia {{ $orden->prioridad === 'URGENTE' ? 'insignia-cancelada' : 'insignia-inactivo' }}">{{ $prioridades[$orden->prioridad] ?? $orden->prioridad }}</span>
                                </td>
                                <td><span class="insignia {{ $orden->claseEstado() }}">{{ $orden->textoEstado() }}</span></td>
                                <td class="whitespace-nowrap">{{ $orden->fecha_solicitud->format('d/m/Y H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-500">
                                    {{ $hayFiltros ? 'No se encontraron órdenes con esos filtros.' : 'No hay órdenes de laboratorio.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-paginacion :paginador="$ordenes" />
        </div>
    </div>
</x-app-layout>
