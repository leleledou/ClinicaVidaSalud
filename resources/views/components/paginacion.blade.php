{{-- Paginación en español (la de Laravel sale en inglés con APP_LOCALE=en). Recibe un LengthAwarePaginator. --}}
@props(['paginador'])

@if ($paginador->total() > 0)
    <nav class="flex items-center justify-between text-sm text-gray-600" aria-label="Paginación">
        <p>
            Mostrando {{ $paginador->firstItem() }} a {{ $paginador->lastItem() }} de {{ $paginador->total() }}
        </p>

        @if ($paginador->hasPages())
            <div class="flex items-center gap-2">
                @if ($paginador->onFirstPage())
                    <span class="rounded-md border border-gray-200 px-3 py-1 text-gray-400">Anterior</span>
                @else
                    <a href="{{ $paginador->previousPageUrl() }}" class="rounded-md border border-gray-300 px-3 py-1 hover:bg-gray-50">Anterior</a>
                @endif

                <span>Página {{ $paginador->currentPage() }} de {{ $paginador->lastPage() }}</span>

                @if ($paginador->hasMorePages())
                    <a href="{{ $paginador->nextPageUrl() }}" class="rounded-md border border-gray-300 px-3 py-1 hover:bg-gray-50">Siguiente</a>
                @else
                    <span class="rounded-md border border-gray-200 px-3 py-1 text-gray-400">Siguiente</span>
                @endif
            </div>
        @endif
    </nav>
@endif
