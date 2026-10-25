<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway atiende por HTTPS a través de un proxy: confiar en él para generar URLs https.
        $middleware->trustProxies(at: '*');
        // Alias de nuestros middleware, para usarlos en routes/web.php por su nombre corto.
        $middleware->alias([
            'cuenta.activa' => \App\Http\Middleware\VerificarCuentaActiva::class,
            'inactividad' => \App\Http\Middleware\ControlarInactividad::class,
            'cambio.password' => \App\Http\Middleware\ExigirCambioPassword::class,
        ]);

        // Prueba mínima 9: sin sesión, toda ruta interna va al login.
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Con sesión, entrar al login lleva al panel.
        $middleware->redirectUsersTo(fn () => route('panel'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // CU03: la contraseña actual nunca vuelve al formulario ni queda en la sesión
        // (Laravel ya excluye password y password_confirmation).
        $exceptions->dontFlash([
            'password_actual',
        ]);

        // Requerimiento técnico: ningún error muestra detalles internos, SQL ni credenciales.
        // El detalle queda en storage/logs (Laravel lo registra antes de llegar aquí).
        // Con APP_DEBUG=true se deja la página de error normal de Laravel, para desarrollar.
        // Validación, sesión y errores HTTP (403, 404, 419...) siguen su camino normal
        // y usan las vistas en español de resources/views/errors.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (config('app.debug')) {
                return null;
            }

            if ($e instanceof ValidationException || $e instanceof AuthenticationException
                || $e instanceof HttpExceptionInterface || $e instanceof HttpResponseException) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Ocurrió un error inesperado. Intente nuevamente más tarde.'], 500);
            }

            return response()->view('errors.500', [], 500);
        });
    })->create();
