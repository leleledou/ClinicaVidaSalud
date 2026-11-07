{{-- CU28 Gestionar perfil del paciente (RF-19): datos personales (solo lectura), contacto y preferencias
     de notificación en una sola página. --}}
@php
    $persona = $paciente->persona;
    $documento = $persona->numero_documento
        ? trim((\App\Models\Persona::TIPOS_DOCUMENTO[$persona->tipo_documento] ?? $persona->tipo_documento) . ' ' . $persona->numero_documento
            . ($persona->complemento ? '-' . $persona->complemento : '') . ($persona->expedido ? ' ' . $persona->expedido : ''))
        : 'Sin documento';
    // Valores del formulario: lo escrito si el servidor devolvió errores; si no, lo guardado.
    $volvio = $errors->any();
    $valor = fn ($campo, $guardado) => $volvio ? old($campo) : $guardado;
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Mi cuenta" titulo="Mi perfil" />
    </x-slot>

    <div class="space-y-6">
        {{-- CU28 paso 2: datos personales, solo lectura --}}
        <div class="tarjeta">
            <h2 class="tarjeta-titulo">Datos personales</h2>
            <p class="alerta-info mb-4">Para corregir estos datos acérquese a recepción.</p>
            <dl class="grid grid-cols-1 gap-x-10 xl:grid-cols-2">
                <div class="dato"><dt>Código de paciente</dt><dd>{{ $paciente->codigo }}</dd></div>
                <div class="dato"><dt>Documento</dt><dd>{{ $documento }}</dd></div>
                <div class="dato"><dt>Nombres</dt><dd>{{ $persona->nombres }}</dd></div>
                <div class="dato"><dt>Apellidos</dt><dd>{{ trim($persona->apellido_paterno . ' ' . $persona->apellido_materno) }}</dd></div>
                <div class="dato"><dt>Fecha de nacimiento</dt><dd>{{ $persona->fecha_nacimiento?->format('d/m/Y') }}</dd></div>
                <div class="dato"><dt>Edad</dt><dd>{{ $persona->edad }} años</dd></div>
                <div class="dato"><dt>Sexo</dt><dd>{{ $persona->sexo === 'F' ? 'Femenino' : 'Masculino' }}</dd></div>
                <div class="dato"><dt>Correo de inicio de sesión</dt><dd>{{ $usuario->correo }}</dd></div>
            </dl>
        </div>

        {{-- CU28 pasos 3 a 5: contacto y preferencias. novalidate: la validación la hace validarPerfil() (más abajo), en español --}}
        <form method="POST" action="{{ route('perfil.modificar') }}" novalidate
              onsubmit="return validarPerfil(this)" class="grid grid-cols-1 items-start gap-6 lg:grid-cols-2">
            @csrf

            {{-- CU28 paso 3: contacto (Persona) --}}
            <div class="tarjeta space-y-6">
                <h2 class="tarjeta-titulo !mb-0">Datos de contacto</h2>

                <div>
                    <x-input-label for="telefono" value="Teléfono" />
                    <x-text-input id="telefono" name="telefono" type="tel" class="mt-1 block w-full" maxlength="20" :value="$valor('telefono', $persona->telefono)" />
                    <p class="js-error" data-campo="telefono" hidden></p>
                    <x-input-error :messages="$errors->get('telefono')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="correo" value="Correo de contacto (opcional)" />
                    <x-text-input id="correo" name="correo" type="email" class="mt-1 block w-full" maxlength="255" :value="$valor('correo', $persona->correo)" />
                    <p class="mt-1 text-xs text-slate-500">No cambia el correo con el que inicia sesión.</p>
                    <p class="js-error" data-campo="correo" hidden></p>
                    <x-input-error :messages="$errors->get('correo')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="direccion" value="Dirección (opcional)" />
                    <x-text-input id="direccion" name="direccion" type="text" class="mt-1 block w-full" maxlength="255" :value="$valor('direccion', $persona->direccion)" />
                    <x-input-error :messages="$errors->get('direccion')" class="mt-2" />
                </div>
            </div>

            {{-- CU28 paso 3: preferencias de notificación (Usuario) --}}
            <div class="tarjeta space-y-6">
                <h2 class="tarjeta-titulo !mb-0">Preferencias de notificación</h2>

                <div class="space-y-2">
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="notificar_por_correo" value="1" class="casilla"
                               @checked($valor('notificar_por_correo', $usuario->notificar_por_correo))>
                        Recibir avisos por correo
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="notificar_por_push" value="1" class="casilla"
                               @checked($valor('notificar_por_push', $usuario->notificar_por_push))>
                        Recibir avisos push
                    </label>
                    <p class="text-xs text-slate-500">Los avisos push se activan en este dispositivo cuando estén disponibles.</p>
                    <x-input-error :messages="array_merge($errors->get('notificar_por_correo'), $errors->get('notificar_por_push'))" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="horas_anticipacion" value="Recordatorio de la cita" />
                    <select id="horas_anticipacion" name="horas_anticipacion" class="campo">
                        @foreach ($horas as $hora)
                            <option value="{{ $hora }}" @selected((int) $valor('horas_anticipacion', $usuario->horas_anticipacion) === $hora)>
                                {{ $hora }} {{ $hora === 1 ? 'hora' : 'horas' }} antes
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Se aplica a las citas que se programen o reprogramen desde ahora.</p>
                    <x-input-error :messages="$errors->get('horas_anticipacion')" class="mt-2" />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 lg:col-span-2">
                <x-primary-button>Guardar cambios</x-primary-button>
                <a href="{{ route('panel') }}" class="btn-secundario">Volver al panel</a>
            </div>
        </form>
    </div>

    {{-- Validación en el cliente (CU28 paso 4). El servidor vuelve a validar lo mismo. --}}
    <script>
        function validarPerfil(form) {
            form.querySelectorAll('.js-error').forEach(function (p) { p.hidden = true; p.textContent = ''; });
            const valor = (campo) => form.elements[campo].value.trim();
            const errores = {};

            // Mismo formato que Persona::FORMATO_TELEFONO.
            if (valor('telefono') === '') {
                errores.telefono = 'El campo Teléfono es obligatorio.';
            } else if (! /^[0-9+\-\s]{7,20}$/.test(valor('telefono'))) {
                errores.telefono = 'El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).';
            }
            if (valor('correo') !== '' && ! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(valor('correo'))) {
                errores.correo = 'El correo no tiene un formato válido.';
            }

            // El mensaje se muestra junto a su campo.
            Object.entries(errores).forEach(function ([campo, mensaje]) {
                const p = form.querySelector('.js-error[data-campo="' + campo + '"]');
                p.textContent = mensaje;
                p.hidden = false;
            });

            return Object.keys(errores).length === 0;
        }
    </script>
</x-app-layout>
