<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Rol (ASIGNADO_A hacia Usuario; OTORGA hacia Permiso).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE roles ADD CONSTRAINT ck_roles_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        // V3: nombre del rol único (ADMINISTRADOR, RECEPCIONISTA, MEDICO, PACIENTE).
        DB::statement('CREATE UNIQUE INDEX ux_roles_nombre ON roles (lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
