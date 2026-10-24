<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Tres aseguradoras con convenio, ficticias (el PUDS-UML registra tres). NIT únicos de prueba. */
class AseguradoraSeeder extends Seeder
{
    private const ASEGURADORAS = [
        ['Seguros Andinos Salud', '1020304011', '4-4000101'],
        ['Previsora Vida Segura', '2030405022', '4-4000202'],
        ['Salud Integral Mutual', '3040506033', '4-4000303'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::ASEGURADORAS as [$nombre, $nit, $telefono]) {
                DB::table('aseguradoras')->insertOrIgnore([
                    'nombre' => $nombre, 'nit' => $nit, 'telefono' => $telefono, 'estado' => 'ACTIVO',
                ]);
            }
        });
    }
}
