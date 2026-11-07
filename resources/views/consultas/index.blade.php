{{-- CU31 Atender consulta médica (RF-32) paso 7: «Mis consultas» del médico. Primero las EN_CURSO (para
     continuarlas) y luego las finalizadas de los últimos 30 días. --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Atención clínica" titulo="Mis consultas">
            <a href="{{ route('citas.agenda') }}" class="font-semibold text-teal-700 hover:underline">Mi agenda</a>
        </x-encabezado>
    </x-slot>

    <div class="space-y-6">
        <p class="alerta-info">Las consultas se inician desde la agenda o desde la cita, cuando llega su hora.</p>

        <div class="tarjeta space-y-4">
            <h2 class="tarjeta-titulo">Consultas en curso y de los últimos 30 días</h2>
            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            <th>Inicio</th>
                            <th>Cita</th>
                            <th>Paciente</th>
                            <th>Especialidad</th>
                            <th>Diagnóstico</th>
                            <th>Estado</th>
                            <th class="text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($consultas as $consulta)
                            @php $enCurso = $consulta->estado === 'EN_CURSO'; @endphp
                            <tr class="align-top">
                                <td class="whitespace-nowrap">{{ $consulta->inicio->format('d/m/Y H:i') }}</td>
                                <td class="whitespace-nowrap">{{ $consulta->cita->codigo }}</td>
                                <td>
                                    {{ $consulta->cita->paciente->persona->nombre_completo }}
                                    <span class="text-slate-500">({{ $consulta->cita->paciente->codigo }})</span>
                                </td>
                                <td>{{ $consulta->cita->ficha->horario->medicoEspecialidad->especialidad->nombre }}</td>
                                <td>
                                    @if ($consulta->codigo_cie10)
                                        <span class="font-semibold">{{ $consulta->codigo_cie10 }}</span>
                                    @endif
                                    {{ \Illuminate\Support\Str::limit($consulta->diagnostico ?? '—', 60) }}
                                </td>
                                <td>
                                    <span class="insignia {{ $enCurso ? 'insignia-programada' : 'insignia-activo' }}">{{ $enCurso ? 'En curso' : 'Finalizada' }}</span>
                                </td>
                                <td>
                                    <div class="acciones">
                                        <a href="{{ route('consultas.mostrar', $consulta->id) }}" class="accion">{{ $enCurso ? 'Continuar consulta' : 'Ver consulta' }}</a>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-8 text-center text-slate-500">No tiene consultas en curso ni finalizadas en los últimos 30 días.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-paginacion :paginador="$consultas" />
        </div>
    </div>
</x-app-layout>
