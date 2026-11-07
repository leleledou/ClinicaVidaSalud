{{-- CU31 paso 3: botón de la consulta junto a los de CU18 (Cita::accionConsulta): «Iniciar consulta» (POST,
     la cita viaja oculta), «Continuar consulta» (EN_CURSO) o «Ver consulta» (FINALIZADA). Solo para el médico de la cita. --}}
@php $accionConsulta = $cita->accionConsulta(auth()->user()); @endphp

@if ($accionConsulta === 'iniciar')
    <form method="POST" action="{{ route('consultas.guardar') }}" class="inline">
        @csrf
        <input type="hidden" name="cita_id" value="{{ $cita->id }}">
        <button type="submit" class="inline-flex items-center rounded-lg bg-marino-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-marino-800">Iniciar consulta</button>
    </form>
@elseif ($accionConsulta)
    <a href="{{ route('consultas.mostrar', $cita->consulta->id) }}"
       class="inline-flex items-center rounded-lg border border-marino-900 px-3 py-1.5 text-xs font-semibold text-marino-900 transition hover:bg-slate-50">
        {{ $accionConsulta === 'continuar' ? 'Continuar consulta' : 'Ver consulta' }}
    </a>
@endif
