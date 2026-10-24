<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Ocho ambientes físicos ficticios (el PUDS-UML registra ocho, incluido el gabinete OD-2). */
class ConsultorioSeeder extends Seeder
{
    private const CONSULTORIOS = [
        ['Consultorio 101', 'Planta baja, ala norte'],
        ['Consultorio 102', 'Planta baja, ala norte'],
        ['Consultorio 103', 'Planta baja, ala sur (Pediatría)'],
        ['Consultorio 201', 'Primer piso, ala norte (Ginecología)'],
        ['Consultorio 202', 'Primer piso, ala sur (Cardiología)'],
        ['Consultorio 203', 'Primer piso, ala sur (Geriatría)'],
        ['Gabinete OD-1', 'Segundo piso (Odontología)'],
        ['Gabinete OD-2', 'Segundo piso (Odontología)'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::CONSULTORIOS as [$nombre, $ubicacion]) {
                DB::table('consultorios')->insertOrIgnore([
                    'nombre' => $nombre, 'ubicacion' => $ubicacion, 'estado' => 'ACTIVO',
                ]);
            }
        });
    }
}
