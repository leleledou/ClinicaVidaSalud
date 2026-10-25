<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>Clínica Vida Salud</title>

        <!-- Fuentes -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-gray-100">
            @include('layouts.navigation')

            <!-- Encabezado de la página -->
            @isset($header)
                <header class="bg-white shadow">
                    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            {{-- Mensajes de confirmación (exito) y avisos (aviso) de cualquier pantalla interna --}}
            @if (session('exito') || session('aviso'))
                <div class="max-w-7xl mx-auto pt-6 px-4 sm:px-6 lg:px-8 space-y-3">
                    @if (session('exito'))
                        <div class="rounded-md bg-green-50 p-4 text-sm font-medium text-green-800">
                            {{ session('exito') }}
                        </div>
                    @endif

                    @if (session('aviso'))
                        <div class="rounded-md bg-yellow-50 p-4 text-sm font-medium text-yellow-800">
                            {{ session('aviso') }}
                        </div>
                    @endif
                </div>
            @endif

            <!-- Contenido de la página -->
            <main>
                {{ $slot }}
            </main>
        </div>
    </body>
</html>
