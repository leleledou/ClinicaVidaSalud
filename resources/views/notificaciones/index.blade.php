{{-- CU38 Consultar notificaciones (RF-39) pasos 1 a 3: bandeja de la persona del usuario (canal SISTEMA o PUSH, ya
     programadas, sin CANCELADA ni PENDIENTE salvo PUSH), las no leídas primero; filtro «Todas / No leídas»; marcar
     una o todas como leídas. VALOR_CRITICO en rojo. CU37 paso 4: botón «Activar avisos en este navegador». --}}
@php
    // Color de cada tipo (CU38 paso 2): crítico en rojo, citas canceladas en ámbar, el resto en azul o verde.
    $claseTipo = fn ($tipo) => match ($tipo) {
        'VALOR_CRITICO' => 'insignia-cancelada',
        'CITA_CANCELADA' => 'insignia-inasistencia',
        'RESULTADO_LISTO' => 'insignia-activo',
        'VERIFICAR_CORREO' => 'insignia-inactivo',
        default => 'insignia-programada',
    };
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Inicio / Notificaciones" titulo="Notificaciones" />
    </x-slot>

    <div class="space-y-6">
        @if ($errors->has('operacion'))
            <div class="alerta-error">{{ $errors->first('operacion') }}</div>
        @endif

        {{-- CU37 paso 4: permiso del navegador para mostrar los avisos (notificar_por_push de la cuenta) --}}
        <div class="tarjeta space-y-3">
            <h2 class="tarjeta-titulo !mb-0">Avisos en este navegador</h2>
            @if ($push)
                <p class="text-sm text-slate-600">Con la sesión abierta, los avisos nuevos aparecen como notificación del navegador en menos de un minuto.</p>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" class="btn-secundario" data-activar-avisos>Activar avisos en este navegador</button>
                    <span class="text-sm text-slate-500" data-estado-avisos></span>
                </div>
            @else
                <p class="text-sm text-slate-600">Su cuenta no tiene activados los avisos push: los avisos nuevos solo se ven en esta bandeja y en el contador del menú.
                    @if (Auth::user()->persona?->paciente && Gate::allows('perfil.gestionar'))
                        Puede activarlos en <a href="{{ route('perfil.mostrar') }}" class="text-teal-700 hover:underline">Mi perfil</a>.
                    @endif
                </p>
            @endif
        </div>

        <div class="tarjeta space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="tarjeta-titulo !mb-0">Bandeja ({{ $notificaciones->total() }}) · {{ $noLeidas }} sin leer</h2>

                <div class="flex flex-wrap items-center gap-3">
                    {{-- CU38 paso 2: filtro --}}
                    <form method="GET" action="{{ route('notificaciones.listar') }}" class="flex items-center gap-2">
                        <label for="filtro" class="sr-only">Mostrar</label>
                        <select id="filtro" name="filtro" class="campo !w-auto" onchange="this.form.submit()">
                            @foreach ($filtros as $valor => $nombre)
                                <option value="{{ $valor }}" @selected($filtro === $valor)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                        <noscript><button class="btn-secundario btn-chico">Ver</button></noscript>
                    </form>

                    {{-- CU38 paso 3: todas como leídas --}}
                    @if ($noLeidas > 0)
                        <form method="POST" action="{{ route('notificaciones.leer-todas') }}">
                            @csrf
                            <button class="btn-primario btn-chico">Marcar todas como leídas</button>
                        </form>
                    @endif
                </div>
            </div>

            <ul class="divide-y divide-slate-100">
                @forelse ($notificaciones as $notificacion)
                    <li @class([
                        'flex flex-col gap-3 py-4 sm:flex-row sm:items-start sm:justify-between',
                        'border-l-4 border-red-600 ps-3' => $notificacion->esCritica(),
                        'border-l-4 border-teal-500 ps-3' => ! $notificacion->esCritica() && ! $notificacion->leida(),
                    ])>
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="insignia {{ $claseTipo($notificacion->tipo) }}">
                                    @if ($notificacion->esCritica())
                                        {{-- Ícono de alerta del aviso crítico --}}
                                        <svg class="me-1 inline h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m0 3.75h.008M10.3 3.86 1.82 18a1.5 1.5 0 0 0 1.29 2.25h17.78A1.5 1.5 0 0 0 22.18 18L13.7 3.86a1.5 1.5 0 0 0-2.6 0Z" />
                                        </svg>
                                    @endif
                                    {{ $notificacion->textoTipo() }}
                                </span>
                                @unless ($notificacion->leida())
                                    <span class="text-xs font-semibold text-teal-700">Sin leer</span>
                                @endunless
                            </div>
                            <p @class(['font-semibold', 'text-red-700' => $notificacion->esCritica(), 'text-marino-900' => ! $notificacion->esCritica()])>{{ $notificacion->titulo }}</p>
                            <p class="text-sm text-slate-700">{{ $notificacion->mensaje }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $notificacion->programada_para->format('d/m/Y H:i') }}
                                @if ($notificacion->leida_en)
                                    · leída el {{ $notificacion->leida_en->format('d/m/Y H:i') }}
                                @endif
                                @if ($enlaces[$notificacion->id] ?? null)
                                    · <a href="{{ $enlaces[$notificacion->id] }}" class="font-medium text-teal-700 hover:underline">Ver detalle</a>
                                @endif
                            </p>
                        </div>

                        @unless ($notificacion->leida())
                            <form method="POST" action="{{ route('notificaciones.leer') }}" class="shrink-0">
                                @csrf
                                <input type="hidden" name="id" value="{{ $notificacion->id }}">
                                <button class="btn-secundario btn-chico">Marcar como leída</button>
                            </form>
                        @endunless
                    </li>
                @empty
                    <li class="py-8 text-center text-slate-500">
                        {{ $filtro === 'NO_LEIDAS' ? 'No tiene notificaciones sin leer.' : 'No tiene notificaciones.' }}
                    </li>
                @endforelse
            </ul>

            <x-paginacion :paginador="$notificaciones" />
        </div>
    </div>
</x-app-layout>
