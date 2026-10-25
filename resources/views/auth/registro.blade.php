{{-- CU12 Autorregistrarse como paciente: formulario público (sin sesión, mayor de edad). --}}
@php
    $claseSelect = 'campo';
@endphp

<x-guest-layout :ancho="true">
    <h2 class="tarjeta-titulo !mb-2">Crear mi cuenta de paciente</h2>
    <p class="alerta-info">
        Para mayores de {{ $edadMayoria }} años. Si eres menor de edad, tu responsable legal debe registrarte en recepción.
    </p>

    {{-- CU12 paso 5 / A1: mensajes que no corresponden a un solo campo (cuenta en uso, datos ya registrados) --}}
    @if ($errors->has('registro'))
        <div class="alerta-error mt-4 font-medium">
            {{ $errors->first('registro') }}
        </div>
    @endif

    {{-- CU12 paso 2: formulario. novalidate: la validación la hace validarRegistro() (resources/js/personas.js), en español. --}}
    <form id="form-registro" method="POST" action="{{ route('registro.guardar') }}" class="mt-6 space-y-8" novalidate>
        @csrf

        {{-- Datos personales (documento obligatorio) --}}
        <fieldset class="space-y-4">
            <legend class="text-base font-semibold text-marino-900">Datos personales</legend>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                <div class="sm:col-span-2">
                    <x-input-label for="tipo_documento" value="Tipo de documento" />
                    <select id="tipo_documento" name="tipo_documento" class="{{ $claseSelect }}">
                        <option value="">Seleccione…</option>
                        @foreach (\App\Models\Persona::TIPOS_DOCUMENTO as $valor => $texto)
                            <option value="{{ $valor }}" @selected(old('tipo_documento', 'CI') === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                    <p class="js-error" data-campo="tipo_documento" hidden></p>
                    <x-input-error :messages="$errors->get('tipo_documento')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="numero_documento" value="Número de documento" />
                    <x-text-input id="numero_documento" name="numero_documento" type="text" maxlength="30" :value="old('numero_documento')" />
                    <p class="js-error" data-campo="numero_documento" hidden></p>
                    <x-input-error :messages="$errors->get('numero_documento')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="complemento" value="Complemento (opcional)" />
                    <x-text-input id="complemento" name="complemento" type="text" maxlength="10" :value="old('complemento')" />
                    <x-input-error :messages="$errors->get('complemento')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="expedido" value="Expedido (opcional)" />
                    <select id="expedido" name="expedido" class="{{ $claseSelect }}">
                        <option value="">—</option>
                        @foreach (\App\Models\Persona::EXPEDIDOS as $valor => $texto)
                            <option value="{{ $valor }}" @selected(old('expedido') === $valor)>{{ $texto }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('expedido')" class="mt-2" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="nombres" value="Nombres" />
                    <x-text-input id="nombres" name="nombres" type="text" maxlength="255" :value="old('nombres')" />
                    <p class="js-error" data-campo="nombres" hidden></p>
                    <x-input-error :messages="$errors->get('nombres')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="apellido_paterno" value="Apellido paterno" />
                    <x-text-input id="apellido_paterno" name="apellido_paterno" type="text" maxlength="255" :value="old('apellido_paterno')" />
                    <p class="js-error" data-campo="apellido_paterno" hidden></p>
                    <x-input-error :messages="$errors->get('apellido_paterno')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="apellido_materno" value="Apellido materno (opcional)" />
                    <x-text-input id="apellido_materno" name="apellido_materno" type="text" maxlength="255" :value="old('apellido_materno')" />
                    <x-input-error :messages="$errors->get('apellido_materno')" class="mt-2" />
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="fecha_nacimiento" value="Fecha de nacimiento" />
                    <x-text-input id="fecha_nacimiento" name="fecha_nacimiento" type="date" max="{{ today()->toDateString() }}" :value="old('fecha_nacimiento')" />
                    <p class="js-error" data-campo="fecha_nacimiento" hidden></p>
                    <x-input-error :messages="$errors->get('fecha_nacimiento')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="sexo" value="Sexo" />
                    <select id="sexo" name="sexo" class="{{ $claseSelect }}">
                        <option value="">Seleccione…</option>
                        <option value="F" @selected(old('sexo') === 'F')>Femenino (F)</option>
                        <option value="M" @selected(old('sexo') === 'M')>Masculino (M)</option>
                    </select>
                    <p class="js-error" data-campo="sexo" hidden></p>
                    <x-input-error :messages="$errors->get('sexo')" class="mt-2" />
                </div>
            </div>
        </fieldset>

        {{-- Contacto (el correo es también el de la cuenta) --}}
        <fieldset class="space-y-4">
            <legend class="text-base font-semibold text-marino-900">Contacto</legend>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="telefono" value="Teléfono" />
                    <x-text-input id="telefono" name="telefono" type="text" maxlength="20" :value="old('telefono')" placeholder="Por ejemplo, 70012345" />
                    <p class="js-error" data-campo="telefono" hidden></p>
                    <x-input-error :messages="$errors->get('telefono')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="correo" value="Correo (para iniciar sesión y verificar tu cuenta)" />
                    <x-text-input id="correo" name="correo" type="email" maxlength="255" :value="old('correo')" autocomplete="email" />
                    <p class="js-error" data-campo="correo" hidden></p>
                    <x-input-error :messages="$errors->get('correo')" class="mt-2" />
                </div>
                <div class="sm:col-span-2">
                    <x-input-label for="direccion" value="Dirección (opcional)" />
                    <x-text-input id="direccion" name="direccion" type="text" maxlength="255" :value="old('direccion')" />
                    <x-input-error :messages="$errors->get('direccion')" class="mt-2" />
                </div>
            </div>
        </fieldset>

        {{-- Cuenta --}}
        <fieldset class="space-y-4">
            <legend class="text-base font-semibold text-marino-900">Cuenta</legend>

            <div class="sm:w-1/2 sm:pe-2">
                <x-input-label for="nombre_usuario" value="Nombre de usuario" />
                <x-text-input id="nombre_usuario" name="nombre_usuario" type="text" maxlength="50" :value="old('nombre_usuario')" autocomplete="username" />
                <p class="js-error" data-campo="nombre_usuario" hidden></p>
                <x-input-error :messages="$errors->get('nombre_usuario')" class="mt-2" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="password" value="Contraseña" />
                    <x-text-input id="password" name="password" type="password" autocomplete="new-password" />
                    <p class="js-error" data-campo="password" hidden></p>
                    <x-input-error :messages="$errors->get('password')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="password_confirmation" value="Confirmar contraseña" />
                    <x-text-input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
                    <p class="js-error" data-campo="password_confirmation" hidden></p>
                </div>
            </div>

            {{-- CU12 paso 5: requisitos de la política del CU03, marcados mientras se escribe --}}
            <div>
                <p class="etiqueta">La contraseña debe cumplir:</p>
                <ul id="requisitos" class="mt-2 space-y-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                    <li data-requisito="largo">Al menos 8 caracteres</li>
                    <li data-requisito="mayuscula">Al menos una letra mayúscula</li>
                    <li data-requisito="minuscula">Al menos una letra minúscula</li>
                    <li data-requisito="numero">Al menos un número</li>
                    <li data-requisito="simbolo">Al menos un símbolo</li>
                    <li data-requisito="confirmacion">Igual a la confirmación</li>
                </ul>
            </div>
        </fieldset>

        {{-- Políticas de privacidad (casilla obligatoria) con texto plegable --}}
        <div class="space-y-2">
            <details class="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                <summary class="cursor-pointer font-semibold text-marino-900">¿Cómo usamos tus datos?</summary>
                <p class="mt-2">
                    Clínica Vida Salud usa tus datos personales y de contacto solo para identificarte, gestionar tus citas,
                    tu historia clínica y enviarte avisos relacionados con tu atención. No se comparten con terceros, salvo
                    obligación legal. Solo el personal autorizado puede consultarlos y cada acceso queda registrado.
                    Puedes pedir en recepción que se corrijan tus datos.
                </p>
            </details>
            <label class="flex items-center gap-2 text-sm text-slate-700">
                <input type="checkbox" name="privacidad" value="1" @checked(old('privacidad'))
                       class="casilla">
                Acepto las políticas de privacidad
            </label>
            <p class="js-error" data-campo="privacidad" hidden></p>
            <x-input-error :messages="$errors->get('privacidad')" />
        </div>

        <div class="flex flex-col-reverse items-center gap-4 sm:flex-row sm:justify-between">
            <a href="{{ route('login') }}" class="text-sm font-semibold text-teal-700 hover:underline">Ya tengo cuenta: iniciar sesión</a>
            <x-primary-button>Crear cuenta</x-primary-button>
        </div>
    </form>

    {{-- CU12 pasos 2 y 5: validación en el navegador (se espera a DOMContentLoaded porque app.js se carga
         como módulo). El servidor vuelve a validar lo mismo. --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const formulario = document.getElementById('form-registro');
            const password = document.getElementById('password');
            const confirmacion = document.getElementById('password_confirmation');

            // Política del CU03 (resources/js/password.js) más la confirmación.
            const pintar = window.requisitosPassword({
                lista: document.getElementById('requisitos'),
                campos: [password, confirmacion],
                estado: () => Object.assign(window.revisarPassword(password.value), {
                    confirmacion: password.value !== '' && password.value === confirmacion.value,
                }),
                escrito: () => password.value !== '',
            });

            formulario.addEventListener('submit', function (evento) {
                pintar(true);
                if (! window.validarRegistro(formulario, {{ $edadMayoria }})) {
                    evento.preventDefault();
                }
            });
        });
    </script>
</x-guest-layout>
