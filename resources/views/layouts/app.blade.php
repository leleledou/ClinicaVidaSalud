<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Clínica Vida Salud</title>

        <!-- Fuentes -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-slate-100 text-slate-800">
        {{-- menuAbierto: cajón del menú lateral en pantallas pequeñas --}}
        <div x-data="{ menuAbierto: false }" @keydown.escape.window="menuAbierto = false" class="min-h-screen">
            @include('layouts.navigation')

            <div class="lg:ps-72 flex min-h-screen flex-col">
                @php
                    // Datos ya cargados por el menú lateral (layouts.navigation): no hace otra consulta.
                    $usuarioCuenta = Auth::user()->loadMissing(['persona', 'rol']);
                    $nombreCuenta = $usuarioCuenta->persona->nombres . ' ' . $usuarioCuenta->persona->apellido_paterno;
                    $inicialesCuenta = mb_strtoupper(mb_substr($usuarioCuenta->persona->nombres, 0, 1) . mb_substr($usuarioCuenta->persona->apellido_paterno, 0, 1));
                    $rolCuenta = mb_convert_case(mb_strtolower($usuarioCuenta->rol->nombre), MB_CASE_TITLE);
                @endphp

                <!-- Barra superior -->
                <header class="sticky top-0 z-30 border-b border-slate-200 bg-white px-4 py-4 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between gap-4">
                        <div class="flex min-w-0 items-center gap-3">
                            <!-- Botón del menú (celular y tablet) -->
                            <button type="button" @click="menuAbierto = true" class="-ms-1 inline-flex items-center justify-center rounded-lg p-2 text-slate-500 hover:bg-slate-100 hover:text-marino-900 focus:outline-none focus:ring-2 focus:ring-teal-500 lg:hidden" aria-label="Abrir el menú">
                                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                                </svg>
                            </button>

                            <!-- Encabezado de la página -->
                            @isset($header)
                                <div class="min-w-0">
                                    {{ $header }}
                                </div>
                            @endisset
                        </div>

                        <!-- Menú de la cuenta -->
                        <x-dropdown align="right" width="w-64">
                            <x-slot name="trigger">
                                <button type="button" class="flex items-center gap-3 rounded-full p-1 text-start hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-teal-500 sm:rounded-xl sm:pe-3">
                                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-teal-50 font-bold text-teal-800">{{ $inicialesCuenta }}</span>
                                    <span class="hidden sm:block">
                                        <span class="block font-semibold leading-tight text-marino-900">{{ $nombreCuenta }}</span>
                                        <span class="block text-sm text-slate-500">{{ $rolCuenta }}</span>
                                    </span>
                                    <svg class="hidden h-4 w-4 text-slate-400 sm:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <!-- Usuario y rol -->
                                <div class="border-b border-slate-100 px-4 py-3 text-xs text-slate-500">
                                    <div class="font-semibold text-marino-900 sm:hidden">{{ $nombreCuenta }}</div>
                                    <div>{{ $usuarioCuenta->nombre_usuario }}</div>
                                    <div>{{ $usuarioCuenta->rol->nombre }}</div>
                                </div>

                                <!-- CU03 Cambiar contraseña -->
                                <x-dropdown-link :href="route('password.editar')">
                                    Cambiar contraseña
                                </x-dropdown-link>

                                <!-- CU02 Cerrar sesión (POST con token CSRF) -->
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf

                                    <x-dropdown-link :href="route('logout')"
                                            onclick="event.preventDefault();
                                                        this.closest('form').submit();">
                                        Cerrar sesión
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </header>

                <div class="flex-1 space-y-6 p-4 sm:p-6 lg:p-8">
                    {{-- Mensajes de confirmación (exito) y avisos (aviso) de cualquier pantalla interna --}}
                    @if (session('exito') || session('aviso'))
                        <div class="space-y-3">
                            @if (session('exito'))
                                <div class="alerta-exito font-medium">
                                    {{ session('exito') }}
                                </div>
                            @endif

                            @if (session('aviso'))
                                <div class="alerta-aviso font-medium">
                                    {{ session('aviso') }}
                                </div>
                            @endif
                        </div>
                    @endif

                    <!-- Contenido de la página -->
                    <main class="space-y-6">
                        {{ $slot }}
                    </main>
                </div>
            </div>
        </div>
    </body>
</html>
