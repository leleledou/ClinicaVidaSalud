<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * UML: asociación OTORGA (Rol 0..* — 0..* Permiso), sin atributos propios.
 * No es una de las 33 clases: es la tabla intermedia de la asociación.
 *
 * ON DELETE CASCADE es seguro aquí: es configuración de acceso sin historial.
 * Los roles y permisos no se eliminan físicamente (baja lógica por estado) y
 * `usuarios.rol_id` impide borrar un rol en uso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles_permisos', function (Blueprint $table) {
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();

            $table->primary(['rol_id', 'permiso_id']);
            $table->index('permiso_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles_permisos');
    }
};
