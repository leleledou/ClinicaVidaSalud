<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Medico, subclase de Persona (EJERCE hacia MedicoEspecialidad,
 * AUSENCIA_DE hacia BloqueoAgenda). La asociación /ATIENDE hacia Cita es
 * derivada: no existe columna en `citas`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('registro_profesional')->unique();
            $table->text('biografia')->nullable();
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE medicos ADD CONSTRAINT ck_medicos_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('medicos');
    }
};
