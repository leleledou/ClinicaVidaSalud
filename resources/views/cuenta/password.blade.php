{{-- CU03 Cambiar contraseña (basada en el formulario de contraseña del perfil de Breeze). --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Mi cuenta" titulo="Cambiar contraseña" />
    </x-slot>

    <div class="space-y-6">
        <div class="space-y-6">
            <div class="tarjeta max-w-2xl">
                <div>
                    <section>
                        <header>
                            <h2 class="tarjeta-titulo">
                                Cambiar contraseña
                            </h2>

                            <p class="alerta-info">
                                Al guardar, se cerrarán sus sesiones abiertas en otros equipos.
                            </p>
                        </header>

                        {{-- CU03 paso 2: formulario. novalidate: la validación la hace el script de abajo. --}}
                        <form id="form-password" method="POST" action="{{ route('password.actualizar') }}" class="mt-6 space-y-6" novalidate>
                            @csrf

                            <!-- Contraseña actual -->
                            <div>
                                <x-input-label for="password_actual" value="Contraseña actual" />
                                <x-text-input id="password_actual" name="password_actual" type="password" autocomplete="current-password" required />
                                <x-input-error :messages="$errors->get('password_actual')" class="mt-2" />
                            </div>

                            <!-- Nueva contraseña -->
                            <div>
                                <x-input-label for="password" value="Nueva contraseña" />
                                <x-text-input id="password" name="password" type="password" autocomplete="new-password" required />
                                <x-input-error :messages="$errors->get('password')" class="mt-2" />
                            </div>

                            <!-- Confirmación -->
                            <div>
                                <x-input-label for="password_confirmation" value="Confirmar nueva contraseña" />
                                <x-text-input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required />
                                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
                            </div>

                            {{-- CU03 paso 5: requisitos de la política; el script los marca mientras se escribe. --}}
                            <div>
                                <p class="etiqueta">La nueva contraseña debe cumplir:</p>
                                <ul id="requisitos" class="mt-2 space-y-1 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
                                    <li data-requisito="largo">Al menos 8 caracteres</li>
                                    <li data-requisito="mayuscula">Al menos una letra mayúscula</li>
                                    <li data-requisito="minuscula">Al menos una letra minúscula</li>
                                    <li data-requisito="numero">Al menos un número</li>
                                    <li data-requisito="simbolo">Al menos un símbolo</li>
                                    <li data-requisito="distinta">Distinta de la contraseña actual</li>
                                    <li data-requisito="confirmacion">Igual a la confirmación</li>
                                </ul>
                                <p id="error-actual" class="mt-2 text-sm text-red-600 hidden">El campo Contraseña actual es obligatorio.</p>
                                <p id="error-requisitos" class="mt-2 text-sm text-red-600 hidden">Faltan requisitos de la nueva contraseña (marcados en rojo).</p>
                            </div>

                            <div class="flex items-center gap-4">
                                <x-primary-button>Guardar</x-primary-button>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </div>

    {{-- CU03 paso 5: validación en el navegador mientras se escribe (requisitos compartidos con CU12 en
         resources/js/password.js; se espera a DOMContentLoaded porque app.js se carga como módulo).
         El servidor vuelve a validar. --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const actual = document.getElementById('password_actual');
            const nueva = document.getElementById('password');
            const confirmacion = document.getElementById('password_confirmation');

            // Política compartida (App\Rules\PoliticaPassword) más «distinta» y «confirmación».
            const pintar = window.requisitosPassword({
                lista: document.getElementById('requisitos'),
                campos: [actual, nueva, confirmacion],
                estado: () => Object.assign(window.revisarPassword(nueva.value), {
                    distinta: nueva.value !== '' && nueva.value !== actual.value,
                    confirmacion: nueva.value !== '' && nueva.value === confirmacion.value,
                }),
                escrito: () => nueva.value !== '',
            });

            document.getElementById('form-password').addEventListener('submit', function (evento) {
                const faltaActual = actual.value === '';
                // Al intentar guardar se marcan en rojo los requisitos que faltan.
                const faltanRequisitos = Object.values(pintar(true)).includes(false);

                document.getElementById('error-actual').classList.toggle('hidden', ! faltaActual);
                document.getElementById('error-requisitos').classList.toggle('hidden', ! faltanRequisitos);

                if (faltaActual || faltanRequisitos) {
                    evento.preventDefault();
                }
            });
        });
    </script>
</x-app-layout>
