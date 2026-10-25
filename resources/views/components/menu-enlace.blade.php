{{-- Opción del menú lateral: punto + texto; la activa es una píldora teal-500 hasta el borde izquierdo. --}}
@props(['href', 'active' => false])

<a href="{{ $href }}" @class([
    'me-4 flex items-center gap-3 rounded-r-full px-6 py-2.5 text-sm transition',
    'bg-teal-500 font-semibold text-white shadow-sm' => $active,
    'text-slate-200 hover:bg-marino-800 hover:text-white' => ! $active,
]) @if ($active) aria-current="page" @endif>
    <span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-white' => $active, 'bg-slate-400' => ! $active])></span>
    <span>{{ $slot }}</span>
</a>
