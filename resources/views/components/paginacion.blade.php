{{-- Paginación en español (la de Laravel sale en inglés con APP_LOCALE=en). Recibe un LengthAwarePaginator. --}}
@props(['paginador'])

@if ($paginador->total() > 0)
    <nav class="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600" aria-label="Paginación">
        <p>
            Mostrando {{ $paginador->firstItem() }} a {{ $paginador->lastItem() }} de {{ $paginador->total() }}
        </p>

        @if ($paginador->hasPages())
            <div class="flex items-center gap-2">
                @if ($paginador->onFirstPage())
                    <span class="rounded-lg border border-slate-200 px-3 py-1.5 text-slate-400">Anterior</span>
                @else
                    <a href="{{ $paginador->previousPageUrl() }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium text-marino-900 hover:bg-slate-50">Anterior</a>
                @endif

                <span>Página {{ $paginador->currentPage() }} de {{ $paginador->lastPage() }}</span>

                @if ($paginador->hasMorePages())
                    <a href="{{ $paginador->nextPageUrl() }}" class="rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-medium text-marino-900 hover:bg-slate-50">Siguiente</a>
                @else
                    <span class="rounded-lg border border-slate-200 px-3 py-1.5 text-slate-400">Siguiente</span>
                @endif
            </div>
        @endif
    </nav>
@endif
