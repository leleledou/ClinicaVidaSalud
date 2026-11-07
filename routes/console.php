<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// CU27 Actualizar citas vencidas (RF-29): el Temporizador marca las inasistencias cada 5 minutos.
// withoutOverlapping: no se ejecuta otra vez si la anterior todavía no terminó.
Schedule::command('citas:actualizar-vencidas')->everyFiveMinutes()->withoutOverlapping();
