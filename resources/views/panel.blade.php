{{-- CU01 paso 8: panel de inicio. Saluda a la persona y muestra su rol. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Panel
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6 text-gray-900">
                    <p class="text-lg font-medium">
                        Bienvenido(a), {{ $usuario->persona->nombres }}
                    </p>
                    <p class="mt-1 text-sm text-gray-600">
                        Rol: {{ $usuario->rol->nombre }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
