{{-- CU14 Consultar disponibilidad de fichas (paciente y recepcionista con sesión). El contenido
     está en fichas/_contenido. --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Citas / Disponibilidad" titulo="Médicos y horarios" />
    </x-slot>

    @include('fichas._contenido')
</x-app-layout>
