<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Las seis especialidades del PUDS-UML y las reglas de edad y sexo que el
 * documento fija (RN15, CU06): niño a Pediatría (0-17), adulto a Medicina
 * General (18-59), adulto mayor a Geriatría (60+), Ginecología solo mujeres.
 *
 * Supuestos a confirmar con la docente (V3 los marca «por confirmar»):
 *  - Odontología y Cardiología sin restricción de edad ni sexo.
 *  - Ginecología sin edad mínima.
 *  - La duración sugerida de cada ficha (20 o 30 min); el documento solo exige
 *    que esté entre 10 y 120.
 */
class EspecialidadSeeder extends Seeder
{
    /** nombre, descripción, edad mínima, edad máxima, sexo, duración sugerida */
    private const ESPECIALIDADES = [
        ['Medicina General', 'Atención primaria de pacientes adultos.', 18, 59, null, 20],
        ['Pediatría', 'Atención de niños y adolescentes hasta los 17 años.', 0, 17, null, 30],
        ['Geriatría', 'Atención de adultos mayores desde los 60 años.', 60, null, null, 30],
        ['Ginecología', 'Salud de la mujer.', null, null, 'F', 30],
        ['Odontología', 'Salud bucal y tratamientos dentales.', null, null, null, 30],
        ['Cardiología', 'Diagnóstico y control de enfermedades del corazón.', null, null, null, 30],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::ESPECIALIDADES as [$nombre, $descripcion, $min, $max, $sexo, $duracion]) {
                DB::table('especialidades')->insertOrIgnore([
                    'nombre' => $nombre,
                    'descripcion' => $descripcion,
                    'edad_minima' => $min,
                    'edad_maxima' => $max,
                    'sexo_permitido' => $sexo,
                    'duracion_sugerida_min' => $duracion,
                    'estado' => 'ACTIVO',
                ]);
            }
        });
    }
}
