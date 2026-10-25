{{-- Etiqueta de color por estado de la cita (RF06). --}}
@props(['estado'])

<span @class([
    'insignia',
    'insignia-programada' => $estado === 'PROGRAMADA',
    'insignia-confirmada' => $estado === 'CONFIRMADA',
    'insignia-atendida' => $estado === 'ATENDIDA',
    'insignia-cancelada' => $estado === 'CANCELADA',
    'insignia-inasistencia' => $estado === 'INASISTENCIA',
    'insignia-disponible' => $estado === 'DISPONIBLE',
    'insignia-bloqueada' => $estado === 'BLOQUEADA',
])>{{ $estado }}</span>
