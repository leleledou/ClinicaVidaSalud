<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Paciente, subclase de Persona.
 * REGISTRA (Recepcionista 0..1): si no hay recepcionista, el paciente se
 * autorregistró por la web.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->unique()->constrained('personas')->restrictOnDelete();
            $table->foreignId('recepcionista_registra_id')->nullable()->constrained('recepcionistas')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('estado', 30)->default('ACTIVO');
            $table->timestamp('fecha_registro', 0)->useCurrent();

            $table->index('recepcionista_registra_id');
        });

        DB::statement("ALTER TABLE pacientes ADD CONSTRAINT ck_pacientes_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('pacientes');
    }
};
