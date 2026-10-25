{{-- CU01 Iniciar sesión: acceso con nombre de usuario o correo. --}}
<x-guest-layout>
    {{-- Mensajes arriba del formulario: éxito (cierre de sesión, CU02 paso 4), avisos
         (sesión expirada CU02 A1, cuenta deshabilitada) y errores de CU01 (clave "usuario"). --}}
    @if (session('exito'))
        <div class="mb-4 font-medium text-sm text-green-600">
            {{ session('exito') }}
        </div>
    @endif

    @if (session('aviso'))
        <div class="mb-4 rounded-md bg-yellow-50 p-3 text-sm font-medium text-yellow-800">
            {{ session('aviso') }}
        </div>
    @endif

    <x-input-error :messages="$errors->get('usuario')" class="mb-4" />

    {{-- CU01 paso 2: formulario. novalidate: la validación la hace el script de abajo, en español. --}}
    <form id="form-login" method="POST" action="{{ route('login.ingresar') }}" novalidate>
        @csrf

        <!-- Usuario o correo -->
        <div>
            <x-input-label for="usuario" value="Usuario o correo" />
            <x-text-input id="usuario" class="block mt-1 w-full" type="text" name="usuario" :value="old('usuario')" required autofocus autocomplete="username" />
            <p id="error-usuario" class="mt-2 text-sm text-red-600 hidden">El campo Usuario o correo es obligatorio.</p>
        </div>

        <!-- Contraseña -->
        <div class="mt-4">
            <x-input-label for="password" value="Contraseña" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="current-password" />
            <p id="error-password" class="mt-2 text-sm text-red-600 hidden">El campo Contraseña es obligatorio.</p>
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        {{-- Sin «Recordarme»: la tabla usuarios no tiene remember_token y una sesión
             recordada saltaría el cierre por inactividad (CU02 A1). --}}
        <div class="flex items-center justify-end mt-4">
            <x-primary-button>
                Ingresar
            </x-primary-button>
        </div>
    </form>

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
