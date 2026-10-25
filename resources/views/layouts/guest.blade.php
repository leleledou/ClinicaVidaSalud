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
    <body class="font-sans text-slate-800 antialiased">
        @if ($completo)
            {{-- Página pública a todo el ancho (CU14): barra superior simple con el logo y el acceso --}}
            <div class="min-h-screen bg-slate-100">
                <header class="border-b border-slate-200 bg-white">
                    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:px-8">
                        <a href="{{ route('login') }}" class="flex items-center gap-3">
                            <x-logo />
                            <span>
                                <span class="block text-lg font-bold leading-tight text-marino-900">Vida Salud</span>
                                <span class="block text-sm text-slate-500">Gestión de citas</span>
                            </span>
                        </a>
                        <nav class="flex flex-wrap items-center gap-2">
                            <a href="{{ route('login') }}" class="btn-secundario">Iniciar sesión</a>
                            <a href="{{ route('registro') }}" class="btn-primario">Crear cuenta</a>
                        </nav>
                    </div>
                </header>

                <main class="mx-auto max-w-7xl space-y-6 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    {{ $slot }}
                </main>
            </div>
        @else
            {{-- Pantallas de acceso: tarjeta blanca centrada sobre el degradado de los prototipos --}}
            <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-marino-900 via-marino-800 to-teal-700 px-4 py-10">
                <div @class([
                    'w-full rounded-3xl bg-white p-6 shadow-2xl sm:p-10',
                    'max-w-3xl' => $ancho,
                    'max-w-md' => ! $ancho,
                ])>
                    {{-- Logo y nombre de la clínica --}}
                    <a href="{{ route('login') }}" class="mb-8 flex flex-col items-center text-center">
                        <x-logo tamano="16" />
                        <span class="mt-4 text-3xl font-bold text-marino-900">Clínica Vida Salud</span>
                        <span class="mt-1 text-slate-500">Sistema de gestión de citas médicas</span>
                    </a>

                    {{ $slot }}
                </div>

                {{-- CU14: consulta pública de médicos y horarios; CU01: iniciar sesión --}}
                @unless (request()->routeIs('login'))
                    <nav class="mt-6 flex gap-4 text-sm font-semibold text-teal-100">
                        <a href="{{ route('fichas.disponibilidad') }}" class="hover:text-white hover:underline">Médicos y horarios</a>
                        <span class="text-teal-300">·</span>
                        <a href="{{ route('login') }}" class="hover:text-white hover:underline">Iniciar sesión</a>
                    </nav>
                @endunless
            </div>
        @endif
    </body>
</html>
