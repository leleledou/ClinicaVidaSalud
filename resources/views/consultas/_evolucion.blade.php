{{-- CU32 paso 4: «Registrar evolución» de un tratamiento (estado, respuesta, observación y fin). Se usa en la
     consulta y en el cardex (historias/show). Si el servidor devolvió errores de este tratamiento, se reabre con old(). --}}
@php
    $volvio = (string) old('evolucion_de') === (string) $tratamiento->id;
    $valor = fn ($campo, $actual) => $volvio ? old($campo) : $actual;
    $erroresEvolucion = $volvio ? array_merge($errors->get('evolucion_' . $tratamiento->id), $errors->get('estado'), $errors->get('respuesta'),
        $errors->get('fecha_fin'), $errors->get('observacion_evolucion')) : $errors->get('evolucion_' . $tratamiento->id);
@endphp

<div x-data="{ abierta: {{ Js::from($volvio || ! empty($erroresEvolucion)) }} }" class="mt-2">
    <button type="button" class="accion" @click="abierta = ! abierta">Registrar evolución</button>

    <form x-show="abierta" x-cloak method="POST" action="{{ route('tratamientos.modificar') }}" novalidate
          onsubmit="return validarEvolucion(this)"
          class="mt-3 grid grid-cols-1 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
        @csrf
        <input type="hidden" name="id" value="{{ $tratamiento->id }}">
        <input type="hidden" name="evolucion_de" value="{{ $tratamiento->id }}">
        @if ($erroresEvolucion)
            <x-input-error :messages="$erroresEvolucion" class="sm:col-span-2" />
        @endif
        <div>
            <x-input-label :for="'estado_' . $tratamiento->id" value="Estado" />
            <select id="estado_{{ $tratamiento->id }}" name="estado" class="campo">
                @foreach (\App\Models\Tratamiento::ESTADOS as $codigo => $texto)
                    <option value="{{ $codigo }}" @selected($valor('estado', $tratamiento->estado) === $codigo)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label :for="'respuesta_' . $tratamiento->id" value="Respuesta del paciente" />
            <select id="respuesta_{{ $tratamiento->id }}" name="respuesta" class="campo">
                @foreach (\App\Models\Tratamiento::RESPUESTAS as $codigo => $texto)
                    <option value="{{ $codigo }}" @selected($valor('respuesta', $tratamiento->respuesta) === $codigo)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label :for="'fecha_fin_' . $tratamiento->id" value="Fecha de fin (obligatoria si finaliza o se suspende)" />
            <x-text-input :id="'fecha_fin_' . $tratamiento->id" name="fecha_fin" type="date" :min="$tratamiento->fecha_inicio->toDateString()"
                          :value="$valor('fecha_fin', $tratamiento->fecha_fin?->toDateString())" />
            <p class="js-error" data-campo="fecha_fin" hidden></p>
        </div>
        <div class="sm:col-span-2">
            <x-input-label :for="'observacion_' . $tratamiento->id" value="Observación de la evolución (opcional)" />
            <textarea id="observacion_{{ $tratamiento->id }}" name="observacion_evolucion" rows="2" maxlength="2000" class="campo">{{ $valor('observacion_evolucion', $tratamiento->observacion_evolucion) }}</textarea>
        </div>
        <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
            <x-primary-button>Guardar evolución</x-primary-button>
            <x-secondary-button @click="abierta = false">Cancelar</x-secondary-button>
        </div>
    </form>
</div>

@once
    {{-- Validación en el cliente (CU32 paso 4). El servidor vuelve a validar lo mismo. --}}
    <script>
        function validarEvolucion(form) {
            const p = form.querySelector('.js-error[data-campo="fecha_fin"]');
            const estado = form.elements['estado'].value;
            const fin = form.elements['fecha_fin'].value;
            let error = '';
            if (estado !== 'EN_CURSO' && fin === '') {
                error = 'Indique la fecha de fin del tratamiento finalizado o suspendido.';
            } else if (fin !== '' && fin < form.elements['fecha_fin'].min) {
                error = 'La fecha de fin no puede ser anterior a la de inicio.';
            }
            p.textContent = error;
            p.hidden = error === '';
            return error === '';
        }
    </script>
@endonce
