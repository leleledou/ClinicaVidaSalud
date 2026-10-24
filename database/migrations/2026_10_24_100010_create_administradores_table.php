<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Administrador, subclase de Persona (AUTORIZA_EXCEPCION hacia Cita).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('administradores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('cargo');
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE administradores ADD CONSTRAINT ck_administradores_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('administradores');
    }
};
