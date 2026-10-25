{{-- Página de bienvenida pública con los accesos de Clínica Vida Salud. --}}
<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Clínica Vida Salud</title>

        <!-- Fuentes -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-800 antialiased">
        <div class="flex min-h-screen items-center justify-center bg-gradient-to-br from-marino-900 via-marino-800 to-teal-700 px-4 py-10">
            <div class="w-full max-w-md rounded-3xl bg-white p-10 text-center shadow-2xl">
                <x-logo tamano="16" />
                <h1 class="mt-4 text-3xl font-bold text-marino-900">Clínica Vida Salud</h1>
                <p class="mt-1 text-slate-500">Sistema de gestión de citas médicas</p>

                <div class="mt-8 space-y-3">
                    <a href="{{ route('login') }}" class="btn-primario w-full py-3 text-base">Iniciar sesión</a>
                    <a href="{{ route('registro') }}" class="btn-secundario w-full py-3 text-base">Crear cuenta</a>
                    <a href="{{ route('fichas.disponibilidad') }}" class="inline-block pt-2 text-sm font-semibold text-teal-700 hover:underline">Ver médicos y horarios</a>
                </div>
            </div>
        </div>
    </body>
</html>
