{{-- CU14 Consultar disponibilidad de fichas: página pública. Con sesión usa el layout interno; sin
     sesión, el layout público a todo el ancho. El contenido es el mismo (fichas/_contenido). --}}
@auth
    <x-app-layout>
        <x-slot name="header">
            <x-encabezado ruta="Citas / Disponibilidad" titulo="Médicos y horarios" />
        </x-slot>

        @include('fichas._contenido')
    </x-app-layout>
@else
    <x-guest-layout :completo="true">
        <x-encabezado ruta="Consulta pública" titulo="Médicos y horarios" />
        @include('fichas._contenido')
    </x-guest-layout>
@endauth
