{{-- Plantilla de las páginas de error (reemplaza errors::minimal de Laravel): tarjeta centrada sobre el
     degradado de las pantallas públicas. Cada página de error define su título, código y mensaje. --}}
<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>@yield('title') · Clínica Vida Salud</title>

        <!-- Fuentes -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans text-slate-800 antialiased">
        <div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-marino-900 via-marino-800 to-teal-700 px-4 py-10">
            <div class="w-full max-w-md rounded-3xl bg-white p-10 text-center shadow-2xl">
                <x-logo tamano="16" />
                <p class="mt-6 text-5xl font-bold text-marino-900">@yield('code')</p>
                <h1 class="mt-2 text-xl font-semibold text-marino-900">@yield('title')</h1>
                <p class="mt-3 text-slate-500">@yield('message')</p>
            </div>
        </div>
    </body>
</html>
