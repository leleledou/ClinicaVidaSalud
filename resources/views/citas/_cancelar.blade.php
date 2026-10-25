{{-- CU17 pasos 2 y 3: formulario de cancelación con motivo obligatorio (máximo 500) y confirm de JavaScript. --}}
@php
    $volvio = old('accion') === 'cancelar' && (string) old('id') === (string) $cita->id;
@endphp

{{-- novalidate: la validación la hace validarCancelacion() (resources/js/citas.js). El servidor valida igual. --}}
<form method="POST" action="{{ route('citas.eliminar') }}" novalidate class="space-y-3"
      onsubmit="return validarCancelacion(this, {{ Js::from($cita->codigo) }})">
    @csrf
    <input type="hidden" name="id" value="{{ $cita->id }}">
    <input type="hidden" name="accion" value="cancelar">

    <div>
        <x-input-label for="motivo_cancelacion_{{ $cita->id }}" value="Motivo de cancelación" />
        <textarea id="motivo_cancelacion_{{ $cita->id }}" name="motivo_cancelacion" rows="2" maxlength="500" required
                  class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ $volvio ? old('motivo_cancelacion') : '' }}</textarea>
        <p class="js-error mt-2 text-sm text-red-600" data-campo="motivo_cancelacion" hidden></p>
        @if ($volvio)
            <x-input-error :messages="$errors->get('motivo_cancelacion')" class="mt-2" />
        @endif
    </div>

    <x-danger-button>Cancelar la cita</x-danger-button>
</form>
