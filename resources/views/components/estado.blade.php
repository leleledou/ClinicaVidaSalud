{{-- Etiqueta de estado: verde ACTIVO, gris INACTIVO (RN11: los registros no se borran, se desactivan).
     También DISPONIBLE (verde agua) y BLOQUEADA (gris oscuro) para las fichas. --}}
@props(['estado'])

<span @class([
    'insignia',
    'insignia-activo' => $estado === 'ACTIVO',
    'insignia-disponible' => $estado === 'DISPONIBLE',
    'insignia-bloqueada' => $estado === 'BLOQUEADA',
    'insignia-inactivo' => ! in_array($estado, ['ACTIVO', 'DISPONIBLE', 'BLOQUEADA'], true),
])>{{ $estado }}</span>
