<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: PacienteSeguro (póliza). ASEGURADO_EN (paciente_id), EMITE
 * (aseguradora_id), VERIFICA (recepcionista_verifica_id, 0..1). CUBRE hacia
 * Cita se resuelve en citas.seguro_paciente_id.
 * Que la póliza sea del mismo paciente de la cita, esté verificada y vigente
 * en la fecha de la cita es regla de negocio (servicio), no de esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seguros_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('aseguradora_id')->constrained('aseguradoras')->restrictOnDelete();
            $table->foreignId('recepcionista_verifica_id')->nullable()->constrained('recepcionistas')->restrictOnDelete();
            $table->string('numero_poliza');
            $table->string('plan')->nullable();
            $table->string('titular');
            $table->date('vigente_desde');
            $table->date('vigente_hasta');
            $table->timestamp('fecha_verificacion', 0)->nullable();
            $table->string('estado', 30)->default('ACTIVO');

            $table->index('paciente_id');
            $table->index('aseguradora_id');
            $table->index('recepcionista_verifica_id');
        });

        DB::statement("ALTER TABLE seguros_paciente ADD CONSTRAINT ck_seguros_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement('ALTER TABLE seguros_paciente ADD CONSTRAINT ck_seguros_vigencia CHECK (vigente_hasta >= vigente_desde)');
        DB::statement('ALTER TABLE seguros_paciente ADD CONSTRAINT ck_seguros_verificacion CHECK ((fecha_verificacion IS NULL) = (recepcionista_verifica_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('seguros_paciente');
    }
};
