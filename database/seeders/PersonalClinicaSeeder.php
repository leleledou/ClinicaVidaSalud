<?php

namespace Database\Seeders;

use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Personal de la clínica (Ciclo 1): un administrador adicional, tres
 * recepcionistas y doce médicos con sus especialidades y cuentas.
 *
 * Tablas: personas, administradores, recepcionistas, medicos,
 * medicos_especialidades, usuarios.
 *
 * Los nombres de los médicos coinciden con los de las consultas de ejemplo del
 * PUDS-UML cuando el documento los menciona; los tres restantes son inventados.
 * Todos los datos son ficticios (correos @vidasalud.test, CI 99xxxxx).
 *
 * Requiere SEED_DEMO_PASSWORD (las cuentas del personal comparten esa
 * contraseña de demostración, almacenada con bcrypt).
 */
class PersonalClinicaSeeder extends Seeder
{
    /** código, nombres, ap. paterno, ap. materno, sexo, nacimiento, ci, usuario, turno, ingreso */
    public const RECEPCIONISTAS = [
        ['REC-001', 'Rosa', 'Mendoza', 'Quispe', 'F', '1990-03-12', '9900101', 'rmendoza', 'MANANA', '2024-02-01'],
        ['REC-002', 'Elena', 'Barrios', 'Flores', 'F', '1988-07-25', '9900102', 'ebarrios', 'TARDE', '2024-05-15'],
        ['REC-003', 'Sandra', 'Ortuño', 'Vega', 'F', '1992-11-02', '9900103', 'sortuno', 'COMPLETO', '2025-01-10'],
    ];

    /**
     * código, nombres, ap. paterno, ap. materno, sexo, nacimiento, ci, registro profesional,
     * usuario, estado, [especialidad => es_principal]
     */
    public const MEDICOS = [
        ['MED-001', 'Juan Carlos', 'Pérez', 'Rojas', 'M', '1974-02-18', '9900201', 'RP-MG-0101', 'dr.perez', 'ACTIVO',
            ['Medicina General' => true, 'Geriatría' => false]],
        ['MED-002', 'Marcelo', 'Vaca', 'Justiniano', 'M', '1981-06-09', '9900202', 'RP-MG-0102', 'dr.vaca', 'ACTIVO',
            ['Medicina General' => true]],
        ['MED-003', 'Silvia', 'Camacho', 'Peña', 'F', '1979-12-01', '9900203', 'RP-MG-0103', 'dra.camacho', 'INACTIVO',
            ['Medicina General' => true]],
        ['MED-004', 'Mónica', 'Fernández', 'Ribera', 'F', '1983-04-14', '9900204', 'RP-PE-0201', 'dra.fernandez', 'ACTIVO',
            ['Pediatría' => true]],
        ['MED-005', 'Daniela', 'Céspedes', 'Durán', 'F', '1989-08-21', '9900205', 'RP-PE-0202', 'dra.cespedes', 'ACTIVO',
            ['Pediatría' => true]],
        ['MED-006', 'Hugo', 'Salvatierra', 'Roca', 'M', '1968-10-05', '9900206', 'RP-GE-0301', 'dr.salvatierra', 'ACTIVO',
            ['Geriatría' => true]],
        ['MED-007', 'Carla', 'Rivero', 'Paz', 'F', '1985-01-27', '9900207', 'RP-GI-0401', 'dra.rivero', 'ACTIVO',
            ['Ginecología' => true]],
        ['MED-008', 'Álvaro', 'Terrazas', 'Medina', 'M', '1977-03-30', '9900208', 'RP-GI-0402', 'dr.terrazas', 'ACTIVO',
            ['Ginecología' => true]],
        ['MED-009', 'Rodrigo', 'Montaño', 'Suárez', 'M', '1972-09-11', '9900209', 'RP-CA-0501', 'dr.montano', 'ACTIVO',
            ['Cardiología' => true]],
        ['MED-010', 'Jorge', 'Gutiérrez', 'Saucedo', 'M', '1980-05-23', '9900210', 'RP-CA-0502', 'dr.gutierrez', 'ACTIVO',
            ['Cardiología' => true, 'Medicina General' => false]],
        ['MED-011', 'Patricia', 'Rojas', 'Antelo', 'F', '1987-07-07', '9900211', 'RP-OD-0601', 'dra.rojas', 'ACTIVO',
            ['Odontología' => true]],
        ['MED-012', 'Ronald', 'Choque', 'Condori', 'M', '1990-12-19', '9900212', 'RP-OD-0602', 'dr.choque', 'ACTIVO',
            ['Odontología' => true]],
    ];

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);
        $hash = Siembra::hashDesdeEntorno('SEED_DEMO_PASSWORD')
            ?? throw new RuntimeException('Defina SEED_DEMO_PASSWORD en su .env local para sembrar cuentas de demostración.');

        DB::transaction(function () use ($hash) {
            $this->administradora($hash);
            $this->recepcionistas($hash);
            $this->medicos($hash);
        });
    }

    private function administradora(string $hash): void
    {
        $personaId = Siembra::persona([
            'tipo_documento' => 'CI', 'numero_documento' => '9900002', 'expedido' => 'CB',
            'nombres' => 'Gabriela', 'apellido_paterno' => 'Soliz', 'apellido_materno' => 'Arce',
            'fecha_nacimiento' => '1985-09-30', 'sexo' => 'F', 'telefono' => '70010002',
            'correo' => 'gabriela.soliz@vidasalud.test',
        ]);
        Siembra::asegurar('administradores', ['persona_id' => $personaId],
            ['codigo' => 'ADM-002', 'cargo' => 'Jefa de Operaciones', 'estado' => 'ACTIVO']);
        Siembra::usuario($personaId, 'ADMINISTRADOR', 'gsoliz', 'gabriela.soliz@vidasalud.test', $hash);
    }

    private function recepcionistas(string $hash): void
    {
        foreach (self::RECEPCIONISTAS as [$codigo, $nombres, $paterno, $materno, $sexo, $nacimiento, $ci, $usuario, $turno, $ingreso]) {
            $correo = "{$usuario}@vidasalud.test";
            $personaId = Siembra::persona([
                'tipo_documento' => 'CI', 'numero_documento' => $ci, 'expedido' => 'CB',
                'nombres' => $nombres, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                'fecha_nacimiento' => $nacimiento, 'sexo' => $sexo, 'telefono' => '7002'.substr($ci, -4),
                'correo' => $correo,
            ]);
            Siembra::asegurar('recepcionistas', ['persona_id' => $personaId],
                ['codigo' => $codigo, 'turno' => $turno, 'fecha_ingreso' => $ingreso, 'estado' => 'ACTIVO']);
            Siembra::usuario($personaId, 'RECEPCIONISTA', $usuario, $correo, $hash);
        }
    }

    private function medicos(string $hash): void
    {
        foreach (self::MEDICOS as [$codigo, $nombres, $paterno, $materno, $sexo, $nacimiento, $ci, $registro, $usuario, $estado, $especialidades]) {
            $correo = "{$usuario}@vidasalud.test";
            $personaId = Siembra::persona([
                'tipo_documento' => 'CI', 'numero_documento' => $ci, 'expedido' => 'LP',
                'nombres' => $nombres, 'apellido_paterno' => $paterno, 'apellido_materno' => $materno,
                'fecha_nacimiento' => $nacimiento, 'sexo' => $sexo, 'telefono' => '7003'.substr($ci, -4),
                'correo' => $correo,
            ]);
            $medicoId = Siembra::asegurar('medicos', ['persona_id' => $personaId], [
                'codigo' => $codigo,
                'registro_profesional' => $registro,
                'biografia' => 'Profesional ficticio de la Clínica Vida Salud (datos de prueba).',
                'estado' => $estado,
            ]);

            foreach ($especialidades as $nombreEsp => $principal) {
                $especialidadId = Siembra::id('especialidades', ['nombre' => $nombreEsp])
                    ?? throw new RuntimeException("Falta la especialidad {$nombreEsp}: ejecute CatalogosSeeder.");
                Siembra::asegurar('medicos_especialidades',
                    ['medico_id' => $medicoId, 'especialidad_id' => $especialidadId],
                    ['es_principal' => $principal, 'estado' => $estado]);
            }

            // Un médico inactivo conserva su historial pero ya no inicia sesión.
            Siembra::usuario($personaId, 'MEDICO', $usuario, $correo, $hash, ['estado' => $estado]);
        }
    }
}
