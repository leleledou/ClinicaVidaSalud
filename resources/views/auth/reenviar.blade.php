{{-- CU12 A2: pedir un nuevo enlace de verificación del correo. --}}
<x-guest-layout>
    @if (session('exito'))
        <div class="alerta-exito mb-4 font-medium">
            {{ session('exito') }}
        </div>
    @endif

    @include('auth._enlace-desarrollo')

    <h2 class="tarjeta-titulo !mb-2">Reenviar verificación</h2>
    <p class="mt-1 text-sm text-slate-600">Escribe el correo de tu cuenta de paciente y te enviaremos un enlace nuevo (vence en 24 horas).</p>

    {{-- novalidate: la validación la hace el script de abajo, en español. El servidor vuelve a validar. --}}
    <form id="form-reenviar" method="POST" action="{{ route('registro.reenviar.enviar') }}" class="mt-4" novalidate>
        @csrf

        <div>
            <x-input-label for="correo" value="Correo" />
            <x-text-input id="correo" name="correo" type="email" maxlength="255" :value="old('correo', $correo)" required autofocus />
            <p id="error-correo" class="mt-2 text-sm text-red-600" hidden></p>
            <x-input-error :messages="$errors->get('correo')" class="mt-2" />
        </div>

        <div class="mt-5 flex flex-col-reverse items-center gap-4 sm:flex-row sm:justify-between">
            @auth
                <a href="{{ route('panel') }}" class="text-sm font-semibold text-teal-700 hover:underline">Volver al panel</a>
            @else
                <a href="{{ route('login') }}" class="text-sm font-semibold text-teal-700 hover:underline">Volver a iniciar sesión</a>
            @endauth
            <x-primary-button>Enviar enlace</x-primary-button>
        </div>
    </form>

    {{-- CU12 A2: correo obligatorio y con formato válido. --}}
    <script>
        document.getElementById('form-reenviar').addEventListener('submit', function (evento) {
            const valor = document.getElementById('correo').value.trim();
            const error = document.getElementById('error-correo');
            let mensaje = '';
            if (valor === '') {
                mensaje = 'El campo Correo es obligatorio.';
            } else if (! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor)) {
                mensaje = 'El correo no tiene un formato válido.';
            }
            error.textContent = mensaje;
            error.hidden = mensaje === '';
            if (mensaje !== '') {
                evento.preventDefault();
            }
        });
    </script>
</x-guest-layout>
