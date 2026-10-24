<?php

namespace Database\Seeders;

use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Administrador inicial: persona, perfil administrativo (ADM-001) y cuenta.
 * Es el único seeder de cuentas permitido en producción.
 *
 * La contraseña sale de SEED_ADMIN_PASSWORD (archivo .env local, nunca
 * versionado). Si no está definida no se crea nada y se informa qué falta; no
 * existe contraseña por defecto. La cuenta nace con cambio obligatorio (CU03).
 *
 * Fuera de producción, los datos personales por defecto son ficticios.
 * En PRODUCCIÓN no se usa ningún dato ficticio: deben definirse todas estas
 * variables con los datos reales de la persona (si falta alguna, se aborta
 * indicando solo los NOMBRES de las variables):
 *   SEED_ADMIN_USUARIO, SEED_ADMIN_EMAIL, SEED_ADMIN_NOMBRES, SEED_ADMIN_APELLIDO_PATERNO,
 *   SEED_ADMIN_DOCUMENTO (CI), SEED_ADMIN_NACIMIENTO (AAAA-MM-DD), SEED_ADMIN_SEXO (M|F),
 *   SEED_ADMIN_TELEFONO. Opcional: SEED_ADMIN_APELLIDO_MATERNO.
 */
class UsuarioInicialSeeder extends Seeder
{
    private const OBLIGATORIAS_EN_PRODUCCION = [
        'SEED_ADMIN_USUARIO', 'SEED_ADMIN_EMAIL', 'SEED_ADMIN_NOMBRES', 'SEED_ADMIN_APELLIDO_PATERNO',
        'SEED_ADMIN_DOCUMENTO', 'SEED_ADMIN_NACIMIENTO', 'SEED_ADMIN_SEXO', 'SEED_ADMIN_TELEFONO',
    ];

    public function run(): void
    {
        $hash = Siembra::hashDesdeEntorno('SEED_ADMIN_PASSWORD');
        if ($hash === null) {
            $this->command?->warn('UsuarioInicialSeeder: no se creó el administrador inicial. '
                .'Defina SEED_ADMIN_PASSWORD en su .env local (no se usa ninguna contraseña por defecto).');

            return;
        }

        $produccion = app()->environment(['production', 'prod']);
        if ($produccion) {
            $faltan = array_values(array_filter(self::OBLIGATORIAS_EN_PRODUCCION, fn (string $v) => ! env($v)));
            if ($faltan !== []) {
                throw new RuntimeException('En producción el administrador inicial usa datos reales. Faltan las variables: '.implode(', ', $faltan).'.');
            }
            if (str_ends_with((string) env('SEED_ADMIN_EMAIL'), '.test')) {
                throw new RuntimeException('SEED_ADMIN_EMAIL no puede ser un correo de prueba (.test) en producción.');
            }
        }

        $usuario = (string) (env('SEED_ADMIN_USUARIO') ?: 'admin');
        $correo = (string) (env('SEED_ADMIN_EMAIL') ?: 'admin@vidasalud.test');

        DB::transaction(function () use ($hash, $usuario, $correo, $produccion) {
            $personaId = Siembra::persona([
                'tipo_documento' => 'CI',
                'numero_documento' => (string) (env('SEED_ADMIN_DOCUMENTO') ?: '9900001'),
                'expedido' => 'CB',
                'nombres' => (string) (env('SEED_ADMIN_NOMBRES') ?: 'Mauricio'),
                'apellido_paterno' => (string) (env('SEED_ADMIN_APELLIDO_PATERNO') ?: 'Salazar'),
                'apellido_materno' => env('SEED_ADMIN_APELLIDO_MATERNO') ?: ($produccion ? null : 'Vargas'),
                'fecha_nacimiento' => (string) (env('SEED_ADMIN_NACIMIENTO') ?: '1980-05-14'),
                'sexo' => (string) (env('SEED_ADMIN_SEXO') ?: 'M'),
                'telefono' => (string) (env('SEED_ADMIN_TELEFONO') ?: '70010001'),
                'correo' => $correo,
                'direccion' => $produccion ? null : 'Av. Ejemplo 100, Cochabamba',
            ]);

            Siembra::asegurar('administradores', ['persona_id' => $personaId], [
                'codigo' => 'ADM-001',
                'cargo' => 'Administrador General',
                'estado' => 'ACTIVO',
            ]);

            Siembra::usuario($personaId, 'ADMINISTRADOR', $usuario, $correo, $hash, [
                'debe_cambiar_password' => true,
            ]);
        });

        $this->command?->info("Administrador inicial verificado (usuario «{$usuario}»).");
    }
}
