{{-- CU23 paso 7 Gestionar aseguradoras (RF-13): ficha de la aseguradora con sus pólizas (solo lectura). --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Catálogos / Aseguradoras" :titulo="'Aseguradora: ' . $aseguradora->nombre">
            <a href="{{ route('aseguradoras.listar') }}" class="font-semibold text-teal-700 hover:underline">Volver a la lista</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        {{-- Datos de la aseguradora --}}
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Datos de la aseguradora</h2>
            <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                <div class="dato">
                    <dt>Nombre</dt>
                    <dd>{{ $aseguradora->nombre }}</dd>
                </div>
                <div class="dato">
                    <dt>Estado</dt>
                    <dd><x-estado :estado="$aseguradora->estado" /></dd>
                </div>
                <div class="dato">
                    <dt>NIT</dt>
                    <dd>{{ $aseguradora->nit }}</dd>
                </div>
                <div class="dato">
                    <dt>Teléfono</dt>
                    <dd>{{ $aseguradora->telefono ?? '—' }}</dd>
                </div>
            </dl>

            {{-- RN20: las pólizas de una aseguradora inactiva no sirven para citas nuevas --}}
            @if ($aseguradora->estado !== 'ACTIVO')
                <p class="alerta-aviso mt-4">La aseguradora está inactiva: sus pólizas no sirven para programar citas nuevas.</p>
            @endif
        </div>

        {{-- Pólizas emitidas por la aseguradora --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Pólizas ({{ $polizas->count() }})</h2>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Paciente</th>
                            <th>Número de póliza</th>
                            <th>Plan</th>
                            <th>Vigencia</th>
                            <th>Estado</th>
                            <th>Verificada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($polizas as $poliza)
                            <tr class="align-top">
                                <td>
                                    {{ $poliza->paciente->persona->nombre_completo }}
                                    <span class="block text-xs text-slate-500">{{ $poliza->paciente->codigo }}</span>
                                </td>
                                <td class="whitespace-nowrap">{{ $poliza->numero_poliza }}</td>
                                <td>{{ $poliza->plan ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $poliza->vigente_desde->format('d/m/Y') }} a {{ $poliza->vigente_hasta->format('d/m/Y') }}</td>
                                <td><x-estado :estado="$poliza->estado" /></td>
                                <td class="whitespace-nowrap">
                                    {{ $poliza->fecha_verificacion ? 'Sí (' . $poliza->fecha_verificacion->format('d/m/Y') . ')' : 'No' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-8 text-center text-slate-500">La aseguradora no tiene pólizas registradas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
