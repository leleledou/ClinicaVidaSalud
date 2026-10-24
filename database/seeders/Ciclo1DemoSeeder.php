<?php

namespace Database\Seeders;

use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Escenario de demostración del Ciclo 1 (nivel 1): personal, 12 pacientes con
 * casos límite, 31 horarios, fichas de siete semanas y 29 citas con historial.
 *
 * Requisitos previos (se comprueban y, si faltan, se detiene sin sembrar):
 *  - CatalogosSeeder ya ejecutado.
 *  - SEED_DEMO_PASSWORD definida.
 * El administrador inicial (UsuarioInicialSeeder) es independiente: la demo trae
 * su propia administradora (ADM-002) para autorizar la excepción de edad.
 *
 * Idempotente. No se ejecuta en producción.
 */
class Ciclo1DemoSeeder extends Seeder
{
    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);
        if (Siembra::id('roles', ['nombre' => 'ADMINISTRADOR']) === null) {
            throw new RuntimeException('Faltan los catálogos: ejecute CatalogosSeeder.');
        }
        if (Siembra::hashDesdeEntorno('SEED_DEMO_PASSWORD') === null) {
            throw new RuntimeException('Defina SEED_DEMO_PASSWORD en su .env local para sembrar cuentas de demostración.');
        }

        $this->call([
            PersonalClinicaSeeder::class,
            PacienteDemoSeeder::class,
            AgendaSeeder::class,
            CitaDemoSeeder::class,
        ]);
    }
}
