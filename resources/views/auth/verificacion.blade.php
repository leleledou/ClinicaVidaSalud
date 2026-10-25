{{-- CU12 paso 8: resultado de abrir el enlace de verificación del correo. --}}
<x-guest-layout>
    @if ($valido)
        <div class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-800">
            {{ $mensaje }}
        </div>
    @else
        <div class="rounded-md bg-red-50 p-4 text-sm font-medium text-red-800">
            {{ $mensaje }}
        </div>
        <p class="mt-4 text-sm text-gray-600">
            Puedes pedir un enlace nuevo:
            <a href="{{ route('registro.reenviar') }}" class="text-indigo-600 hover:underline">Reenviar verificación</a>
        </p>
    @endif

    <div class="mt-6 flex justify-end">
        @auth
            <a href="{{ route('panel') }}" class="text-sm text-indigo-600 hover:underline">Ir al panel</a>
        @else
            <a href="{{ route('login') }}" class="text-sm text-indigo-600 hover:underline">Ir a iniciar sesión</a>
        @endauth
    </div>
</x-guest-layout>
