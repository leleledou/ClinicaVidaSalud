{{-- Encabezado de cada pantalla (slot header): ruta pequeña y título. El contenido (opcional) se muestra
     junto al título: por ejemplo, el enlace «Volver a la lista». --}}
@props(['ruta' => null, 'titulo'])

<div class="min-w-0">
    @if ($ruta)
        <p class="ruta">{{ $ruta }}</p>
    @endif
    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
        <h1 class="titulo">{{ $titulo }}</h1>
        @if ($slot->isNotEmpty())
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">{{ $slot }}</div>
        @endif
    </div>
</div>
