{{-- Logo de los prototipos: cuadrado redondeado teal-500 con un «+» blanco. tamano: 10 (40px) o 16 (64px). --}}
@props(['tamano' => 10])

<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center bg-teal-500 text-white shadow-sm',
    'h-10 w-10 rounded-xl' => $tamano == 10,
    'h-16 w-16 rounded-2xl' => $tamano == 16,
]) }} aria-hidden="true">
    <svg @class(['h-6 w-6' => $tamano == 10, 'h-9 w-9' => $tamano == 16]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14M5 12h14" />
    </svg>
</span>
