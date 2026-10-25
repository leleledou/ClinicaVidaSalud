{{--
    Datos de la persona en los formularios de médico (CU07 paso 4), paciente (CU10 pasos 2 a 5),
    usuario (CU04 paso 4) y vínculo (CU13 paso 3). Se usa dentro del componente Alpine formularioPersona
    (resources/js/personas.js).
    Parámetros: $perfil ('medico' | 'paciente' | 'usuario' | 'vinculo') y $volvioFormulario (mostrar los errores del servidor).
--}}
@php
    $esMedico = $perfil === 'medico';
    $leyendaDocumento = match ($perfil) {
        'medico' => 'Documento',
        'usuario' => 'Documento (obligatorio para el personal: administrador, recepcionista y médico)',
        'vinculo' => 'Documento de la persona vinculada (obligatorio para el responsable legal)',
        default => 'Documento (un menor de edad puede registrarse sin número)',
    };
    // En el formulario de usuario hay además un correo de acceso: este es el de contacto.
    $etiquetaCorreo = match ($perfil) {
        'medico' => 'Correo',
        'usuario' => 'Correo de contacto (opcional)',
        default => 'Correo (opcional)',
    };
    $claseSelect = 'campo';
    // Al modificar un médico el documento no se cambia (los campos deshabilitados no se envían).
    $documentoBloqueado = $esMedico ? 'form.id' : 'false';
@endphp

{{-- Documento --}}
<fieldset class="space-y-4">
    <legend class="text-base font-semibold text-marino-900">{{ $leyendaDocumento }}</legend>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-4 lg:grid-cols-2 2xl:grid-cols-4">
        <div class="sm:col-span-2">
            <x-input-label for="tipo_documento" value="Tipo de documento" />
            <select id="tipo_documento" name="tipo_documento" x-model="form.tipo_documento" :disabled="{{ $documentoBloqueado }}" class="{{ $claseSelect }}">
                <option value="">{{ $esMedico ? 'Seleccione…' : 'Sin documento' }}</option>
                @foreach (\App\Models\Persona::TIPOS_DOCUMENTO as $valor => $texto)
                    <option value="{{ $valor }}">{{ $texto }}</option>
                @endforeach
            </select>
            <p class="js-error" data-campo="tipo_documento" hidden></p>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('tipo_documento')" class="mt-2" /> @endif
        </div>

        <div class="sm:col-span-2">
            <x-input-label for="numero_documento" value="Número" />
            <x-text-input id="numero_documento" name="numero_documento" type="text" maxlength="30"
                          x-model="form.numero_documento" ::disabled="{{ $documentoBloqueado }}" />
            <p class="js-error" data-campo="numero_documento" hidden></p>
            @if ($volvioFormulario)
                <x-input-error :messages="$errors->get('numero_documento')" class="mt-2" />
                {{-- CU10 paso 4: enlace a la ficha del paciente que ya tiene ese documento --}}
                @if (session('paciente_existente'))
                    <a href="{{ route('pacientes.mostrar', session('paciente_existente')['id']) }}" class="mt-1 inline-block text-sm font-semibold text-teal-700 hover:underline">
                        Ver la ficha de {{ session('paciente_existente')['codigo'] }}
                    </a>
                @endif
            @endif
        </div>

        <div>
            <x-input-label for="complemento" value="Complemento (opcional)" />
            <x-text-input id="complemento" name="complemento" type="text" maxlength="10"
                          x-model="form.complemento" ::disabled="{{ $documentoBloqueado }}" />
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('complemento')" class="mt-2" /> @endif
        </div>

        <div>
            <x-input-label for="expedido" value="Expedido (opcional)" />
            <select id="expedido" name="expedido" x-model="form.expedido" :disabled="{{ $documentoBloqueado }}" class="{{ $claseSelect }}">
                <option value="">—</option>
                @foreach (\App\Models\Persona::EXPEDIDOS as $valor => $texto)
                    <option value="{{ $valor }}">{{ $texto }}</option>
                @endforeach
            </select>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('expedido')" class="mt-2" /> @endif
        </div>

        {{-- CU07 paso 4 / CU10 paso 2: buscar si la persona ya está registrada --}}
        <div class="sm:col-span-2 flex items-end" x-show="! form.id">
            <x-secondary-button @click="buscar()">Buscar</x-secondary-button>
        </div>
    </div>

    <div x-show="mensaje" x-cloak
         :class="{ 'alerta-exito': tipoMensaje === 'ok', 'alerta-error': tipoMensaje === 'error', 'alerta-info': tipoMensaje === 'info' }"
         class="font-medium">
        <span x-text="mensaje"></span>
        <a x-show="enlaceFicha" :href="enlaceFicha" class="ms-1 underline">Ver su ficha</a>
    </div>
</fieldset>

{{-- Datos personales. Si se reutiliza una persona ya registrada, no se editan aquí. --}}
<fieldset class="space-y-4" :class="personaReutilizada && 'opacity-75'">
    <legend class="text-base font-semibold text-marino-900">Datos personales</legend>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-1 2xl:grid-cols-2">
        <div>
            <x-input-label for="nombres" value="Nombres" />
            <x-text-input id="nombres" name="nombres" type="text" maxlength="255" x-model="form.nombres" ::readonly="personaReutilizada" />
            <p class="js-error" data-campo="nombres" hidden></p>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('nombres')" class="mt-2" /> @endif
        </div>
        <div>
            <x-input-label for="apellido_paterno" value="Apellido paterno" />
            <x-text-input id="apellido_paterno" name="apellido_paterno" type="text" maxlength="255" x-model="form.apellido_paterno" ::readonly="personaReutilizada" />
            <p class="js-error" data-campo="apellido_paterno" hidden></p>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('apellido_paterno')" class="mt-2" /> @endif
        </div>
        <div>
            <x-input-label for="apellido_materno" value="Apellido materno (opcional)" />
            <x-text-input id="apellido_materno" name="apellido_materno" type="text" maxlength="255" x-model="form.apellido_materno" ::readonly="personaReutilizada" />
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('apellido_materno')" class="mt-2" /> @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-1 2xl:grid-cols-2">
        <div>
            <x-input-label for="fecha_nacimiento" value="Fecha de nacimiento" />
            <x-text-input id="fecha_nacimiento" name="fecha_nacimiento" type="date" max="{{ today()->toDateString() }}" x-model="form.fecha_nacimiento" ::readonly="personaReutilizada" />
            <p class="js-error" data-campo="fecha_nacimiento" hidden></p>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('fecha_nacimiento')" class="mt-2" /> @endif
        </div>
        <div>
            <x-input-label for="sexo" value="Sexo" />
            {{-- Un select readonly no existe: se bloquea con pointer-events (deshabilitarlo no lo enviaría) --}}
            <select id="sexo" name="sexo" x-model="form.sexo" :class="personaReutilizada && 'pointer-events-none bg-slate-100'" :tabindex="personaReutilizada ? -1 : 0" class="{{ $claseSelect }}">
                <option value="">Seleccione…</option>
                <option value="F">Femenino (F)</option>
                <option value="M">Masculino (M)</option>
            </select>
            <p class="js-error" data-campo="sexo" hidden></p>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('sexo')" class="mt-2" /> @endif
        </div>
        <div>
            <x-input-label for="telefono" value="Teléfono" />
            <x-text-input id="telefono" name="telefono" type="text" maxlength="20" x-model="form.telefono" ::readonly="personaReutilizada" placeholder="Por ejemplo, 70012345" />
            <p class="js-error" data-campo="telefono" hidden></p>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('telefono')" class="mt-2" /> @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-1 2xl:grid-cols-2">
        <div>
            <x-input-label for="correo" :value="$etiquetaCorreo" />
            <x-text-input id="correo" name="correo" type="email" maxlength="255" x-model="form.correo" ::readonly="personaReutilizada" />
            <p class="js-error" data-campo="correo" hidden></p>
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('correo')" class="mt-2" /> @endif
        </div>
        <div>
            <x-input-label for="direccion" value="Dirección (opcional)" />
            <x-text-input id="direccion" name="direccion" type="text" maxlength="255" x-model="form.direccion" ::readonly="personaReutilizada" />
            @if ($volvioFormulario) <x-input-error :messages="$errors->get('direccion')" class="mt-2" /> @endif
        </div>
    </div>
</fieldset>
