<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Datos fundamentales, sin personas ni cuentas. Es el perfil mínimo y siempre
 * seguro de ejecutar: solo inserta lo que falta.
 */
class CatalogosSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SeguridadSeeder::class,
            ConfiguracionSistemaSeeder::class,
            EspecialidadSeeder::class,
            AseguradoraSeeder::class,
            ConsultorioSeeder::class,
        ]);
    }
}
