{{-- CU14 Consultar disponibilidad de fichas: página pública. Con sesión usa el layout interno; sin
     sesión, el layout público a todo el ancho. El contenido es el mismo (fichas/_contenido). --}}
@auth
    <x-app-layout>
        <x-slot name="header">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Médicos y horarios</h2>
        </x-slot>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div class="bg-white shadow-sm sm:rounded-lg p-6">
                    @include('fichas._contenido')
                </div>
            </div>
        </div>
    </x-app-layout>
@else
    <x-guest-layout :completo="true">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">Médicos y horarios</h2>
        @include('fichas._contenido')
    </x-guest-layout>
@endauth
