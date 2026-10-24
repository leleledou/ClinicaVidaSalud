<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Usuario (reemplaza a la tabla `users` de Laravel).
 *
 * Adaptaciones pendientes en la etapa de autenticación (no se hacen aquí):
 *  - Renombrar App\Models\User a Usuario con $table = 'usuarios'.
 *  - config/auth.php: providers.users.model => Usuario::class.
 *  - Autenticación por `correo`/`nombre_usuario`; getEmailForPasswordReset() => correo.
 *  - Sin remember_token (no está en el UML): getRememberTokenName() devuelve ''.
 *  - UserFactory => UsuarioFactory; DatabaseSeeder sin User::factory().
 *
 * Asociaciones: ASIGNADO_A (rol_id) y ACCEDE_COMO (persona_id, 0..1 por persona).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas')->restrictOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->restrictOnDelete();
            $table->string('nombre_usuario');
            $table->string('correo'); // correo de inicio de sesión (distinto del contacto de Persona)
            $table->string('password');
            $table->string('estado', 30)->default('ACTIVO');
            $table->boolean('debe_cambiar_password')->default(false);
            $table->smallInteger('intentos_fallidos')->default(0);
            $table->timestamp('bloqueado_hasta', 0)->nullable();
            $table->timestamp('correo_verificado_en', 0)->nullable();
            $table->timestamp('ultimo_acceso', 0)->nullable();
            $table->boolean('notificar_por_correo')->default(true);
            $table->boolean('notificar_por_push')->default(false);
            $table->smallInteger('horas_anticipacion')->default(24);
            $table->jsonb('suscripcion_push')->nullable();

            $table->index('rol_id');
        });

        DB::statement("ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement('ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_intentos CHECK (intentos_fallidos >= 0)');
        DB::statement('ALTER TABLE usuarios ADD CONSTRAINT ck_usuarios_horas_anticipacion CHECK (horas_anticipacion > 0)');
        DB::statement('CREATE UNIQUE INDEX ux_usuarios_nombre_usuario ON usuarios (lower(nombre_usuario))');
        DB::statement('CREATE UNIQUE INDEX ux_usuarios_correo ON usuarios (lower(correo))');
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};
