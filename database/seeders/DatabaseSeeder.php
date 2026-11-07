<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * Coordina la siembra por perfiles. El perfil se elige con la variable de
 * entorno SEED_PERFIL (por defecto «minimo»):
 *
 *   minimo     catálogos (incluido el de laboratorio) + administrador inicial
 *              (si SEED_ADMIN_PASSWORD está definida)
 *   demo       minimo + escenario de demostración del Ciclo 1 + escenario clínico
 *              reducido (caso del niño con anemia, entre otros) + sus notificaciones
 *              y su bitácora (requiere SEED_DEMO_PASSWORD)
 *   extendido  demo + población amplia del PUDS-UML (211 pacientes, 615 citas) con
 *              su atención clínica, laboratorio, notificaciones y bitácora
 *
 * Notificaciones y bitácora van al final: se derivan de todo lo anterior.
 * La población extendida nunca se ejecuta con el perfil mínimo ni con el demo.
 * Cada seeder también puede lanzarse solo con `--class=`.
 *
 * Las contraseñas se leen de variables de entorno locales; no están en el código.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $perfil = strtolower((string) (env('SEED_PERFIL') ?: 'minimo'));
        if (! in_array($perfil, ['minimo', 'demo', 'extendido'], true)) {
            throw new InvalidArgumentException("SEED_PERFIL «{$perfil}» no es válido: use minimo, demo o extendido.");
        }

        $this->call([
            CatalogosSeeder::class,
            UsuarioInicialSeeder::class,
        ]);

        if ($perfil === 'minimo') {
            return;
        }

        $this->call([
            Ciclo1DemoSeeder::class,
            ClinicaDemoSeeder::class,
        ]);

        if ($perfil === 'extendido') {
            $this->call([
                PoblacionExtendidaSeeder::class,
                ClinicaExtendidaSeeder::class,
            ]);
        }

        $this->call([
            NotificacionSeeder::class,
            BitacoraSeeder::class,
        ]);
    }
}
