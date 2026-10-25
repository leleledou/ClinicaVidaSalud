<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // RF01 y RN12: cada código de permiso de roles_permisos se usa como habilidad
        // (rutas con ->middleware("can:codigo") y vistas con @can("codigo")).
        // null deja seguir la evaluación normal, que termina en «denegado».
        Gate::before(fn ($usuario, $permiso) => $usuario->tienePermiso($permiso) ? true : null);
    }
}
