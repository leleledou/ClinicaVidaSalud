<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Recepcionista, subclase de Persona (persona_id UNIQUE: una sola fila por persona en esta subclase).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recepcionistas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('turno', 30);
            $table->date('fecha_ingreso');
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE recepcionistas ADD CONSTRAINT ck_recepcionistas_turno CHECK (turno IN ('MANANA','TARDE','COMPLETO'))");
        DB::statement("ALTER TABLE recepcionistas ADD CONSTRAINT ck_recepcionistas_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('recepcionistas');
    }
};
