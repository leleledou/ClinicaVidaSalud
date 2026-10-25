{{-- CU01 paso 8: panel de inicio. Saluda a la persona y muestra su rol. --}}
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Panel
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            {{-- CU12: el paciente con el correo sin verificar no puede reservar citas en línea --}}
            @if ($correoPendiente)
                <div class="rounded-md bg-yellow-50 p-4 text-sm font-medium text-yellow-800">
                    Verifica tu correo para poder reservar citas en línea.
                    <a href="{{ route('registro.reenviar') }}" class="ms-1 underline">Reenviar el enlace de verificación</a>
                </div>
            @endif

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
