{{-- CU12 paso 8: resultado de abrir el enlace de verificación del correo. --}}
<x-guest-layout>
    @if ($valido)
        <div class="alerta-exito font-medium">
            {{ $mensaje }}
        </div>
    @else
        <div class="alerta-error font-medium">
            {{ $mensaje }}
        </div>
        <p class="mt-4 text-sm text-slate-600">
            Puedes pedir un enlace nuevo:
            <a href="{{ route('registro.reenviar') }}" class="font-semibold text-teal-700 hover:underline">Reenviar verificación</a>
        </p>
    @endif

    <div class="mt-6 flex justify-center">
        @auth
            <a href="{{ route('panel') }}" class="btn-primario w-full">Ir al panel</a>
        @else
            <a href="{{ route('login') }}" class="btn-primario w-full">Ir a iniciar sesión</a>
        @endauth
    </div>
</x-guest-layout>
