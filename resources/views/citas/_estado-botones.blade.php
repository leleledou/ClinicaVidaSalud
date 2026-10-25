{{-- CU18 pasos 2 a 4 y A1: botones de cambio de estado válidos para la cita ($acciones = accionesPermitidas). --}}
@php
    $botones = [
        'confirmar' => ['estado' => 'CONFIRMADA', 'texto' => 'Confirmar', 'clase' => 'bg-teal-700 hover:bg-teal-800 text-white', 'confirmar' => null],
        'atender' => ['estado' => 'ATENDIDA', 'texto' => 'Atendida', 'clase' => 'bg-green-600 hover:bg-green-700 text-white', 'confirmar' => null],
        // CU18 A1: confirm de JavaScript antes de registrar la inasistencia.
        'inasistencia' => ['estado' => 'INASISTENCIA', 'texto' => 'Inasistencia', 'clase' => 'bg-red-600 hover:bg-red-700 text-white',
            'confirmar' => '¿Registrar la inasistencia de la cita ' . $cita->codigo . '? El paciente no se presentó.'],
    ];
@endphp

@foreach ($botones as $accion => $boton)
    @if (in_array($accion, $acciones, true))
        <form method="POST" action="{{ route('citas.estado') }}" class="inline"
              @if ($boton['confirmar']) onsubmit="return confirm({{ Js::from($boton['confirmar']) }})" @endif>
            @csrf
            <input type="hidden" name="id" value="{{ $cita->id }}">
            <input type="hidden" name="estado" value="{{ $boton['estado'] }}">
            <button type="submit" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $boton['clase'] }}">{{ $boton['texto'] }}</button>
        </form>
    @endif
@endforeach
