{{-- Etiqueta de color por estado de la cita (RF06). --}}
@props(['estado'])

<span @class([
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
    'bg-blue-100 text-blue-800' => $estado === 'PROGRAMADA',
    'bg-indigo-100 text-indigo-800' => $estado === 'CONFIRMADA',
    'bg-green-100 text-green-800' => $estado === 'ATENDIDA',
    'bg-gray-200 text-gray-700' => $estado === 'CANCELADA',
    'bg-red-100 text-red-800' => $estado === 'INASISTENCIA',
])>{{ $estado }}</span>
