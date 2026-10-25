{{-- CU01 Iniciar sesión: acceso con nombre de usuario o correo. --}}
<x-guest-layout>
    {{-- Mensajes arriba del formulario: éxito (cierre de sesión, CU02 paso 4), avisos
         (sesión expirada CU02 A1, cuenta deshabilitada) y errores de CU01 (clave "usuario"). --}}
    @if (session('exito'))
        <div class="alerta-exito mb-4 font-medium">
            {{ session('exito') }}
        </div>
    @endif

    @if (session('aviso'))
        <div class="alerta-aviso mb-4 font-medium">
            {{ session('aviso') }}
        </div>
    @endif

    {{-- CU12 paso 7: en desarrollo (MAIL_MAILER=log) se muestra además el enlace de verificación --}}
    @include('auth._enlace-desarrollo')

    @if ($errors->has('usuario'))
        <div class="alerta-error mb-4 font-medium">
            <x-input-error :messages="$errors->get('usuario')" class="!mt-0 !text-red-900" />
        </div>
    @endif

    {{-- CU01 paso 2: formulario. novalidate: la validación la hace el script de abajo, en español. --}}
    <form id="form-login" method="POST" action="{{ route('login.ingresar') }}" novalidate class="space-y-5">
        @csrf

        <!-- Usuario o correo -->
        <div>
            <x-input-label for="usuario" value="Usuario o correo" />
            <x-text-input id="usuario" type="text" name="usuario" :value="old('usuario')" required autofocus autocomplete="username" />
            <p id="error-usuario" class="mt-2 text-sm text-red-600 hidden">El campo Usuario o correo es obligatorio.</p>
        </div>

        <!-- Contraseña -->
        <div>
            <x-input-label for="password" value="Contraseña" />
            <x-text-input id="password" type="password" name="password" required autocomplete="current-password" />
            <p id="error-password" class="mt-2 text-sm text-red-600 hidden">El campo Contraseña es obligatorio.</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        {{-- Sin «Recordarme»: la tabla usuarios no tiene remember_token y una sesión
             recordada saltaría el cierre por inactividad (CU02 A1). --}}
        <x-primary-button class="w-full py-3 text-base">
            Ingresar
        </x-primary-button>
    </form>

    {{-- CU12 Autorregistrarse como paciente / CU14 consulta pública de médicos y horarios --}}
    <p class="mt-6 text-center text-sm text-slate-600">
        ¿Paciente nuevo?
        <a href="{{ route('registro') }}" class="font-semibold text-teal-700 hover:underline">Cree su cuenta</a>
        <span class="mx-1 text-slate-300">·</span>
        <a href="{{ route('fichas.disponibilidad') }}" class="font-semibold text-teal-700 hover:underline">Ver médicos y horarios</a>
    </p>

    {{-- CU01 paso 2: validación en el navegador, ambos campos obligatorios.
         El servidor vuelve a validar (paso 4). --}}
    <script>
        document.getElementById('form-login').addEventListener('submit', function (evento) {
            let valido = true;

            ['usuario', 'password'].forEach(function (campo) {
                const vacio = document.getElementById(campo).value.trim() === '';
                document.getElementById('error-' + campo).classList.toggle('hidden', ! vacio);
                if (vacio) {
                    valido = false;
                }
            });

            if (! valido) {
                evento.preventDefault();
            }
        });
    </script>
</x-guest-layout>
