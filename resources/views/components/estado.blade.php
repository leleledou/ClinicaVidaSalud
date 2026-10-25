{{-- Etiqueta de estado: verde ACTIVO, gris INACTIVO (RN11: los registros no se borran, se desactivan). --}}
@props(['estado'])

<span @class([
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
    'bg-green-100 text-green-800' => $estado === 'ACTIVO',
    'bg-gray-200 text-gray-700' => $estado !== 'ACTIVO',
])>{{ $estado }}</span>
