{{-- CU12 paso 7 / A2: SOLO en desarrollo (APP_ENV=local, MAIL_MAILER=log) se muestra el enlace de
     verificación, porque el correo no sale del equipo. En producción nunca aparece. --}}
@if (app()->environment('local') && session('enlace_verificacion'))
    <div class="mb-4 rounded-md border border-dashed border-indigo-300 bg-indigo-50 p-3 text-sm text-indigo-900">
        <p class="font-semibold">Modo desarrollo: enlace de verificación</p>
        <a href="{{ session('enlace_verificacion') }}" class="mt-1 block break-all underline">{{ session('enlace_verificacion') }}</a>
    </div>
@endif
