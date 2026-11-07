{{-- CU22 Gestionar configuración del sistema (RF-07): lista de parámetros con su valor actual.
     No hay alta ni baja: las claves las usa el código; solo se modifica el valor. --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Seguridad / Configuración del sistema" titulo="Configuración del sistema" />
    </x-slot>

    <div class="space-y-6">
        {{-- CU22 pasos 1-2: lista --}}
        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Parámetros de funcionamiento</h2>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Clave</th>
                            <th>Descripción</th>
                            <th>Tipo de dato</th>
                            <th>Valor actual</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($configuraciones as $configuracion)
                            <tr class="align-top">
                                <td class="whitespace-nowrap font-mono text-xs font-semibold text-marino-900">{{ $configuracion->clave }}</td>
                                <td class="text-slate-600">{{ $configuracion->descripcion ?? '—' }}</td>
                                <td class="whitespace-nowrap">{{ $configuracion->tipo_dato }}</td>
                                <td class="whitespace-nowrap font-semibold">{{ $configuracion->valor }}</td>
                                <td>
                                    <div class="acciones">
                                        {{-- CU22 paso 3 --}}
                                        <a href="{{ route('configuraciones.mostrar', $configuracion->clave) }}" class="accion">Modificar</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-8 text-center text-slate-500">No hay parámetros registrados.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
