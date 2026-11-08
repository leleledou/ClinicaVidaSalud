{{-- CU40 Generar reportes (RF-41) paso 1: elegir el reporte. --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Gestión / Reportes" titulo="Reportes" />
    </x-slot>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach ($tipos as $tipo => $nombre)
            <a href="{{ route('reportes.mostrar', $tipo) }}" class="tarjeta block space-y-2 transition hover:border-teal-500 hover:shadow-md">
                <h2 class="tarjeta-titulo !mb-0">{{ $nombre }}</h2>
                <p class="text-sm text-slate-600">{{ $descripciones[$tipo] }}</p>
                <p class="text-sm font-medium text-teal-700">Generar en pantalla, PDF o Excel/CSV →</p>
            </a>
        @endforeach
    </div>
</x-app-layout>
