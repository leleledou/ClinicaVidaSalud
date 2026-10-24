<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: MedicoEspecialidad (clase asociativa entre Medico y Especialidad).
 *
 * Adaptación de implementación (ADR-01): se agrega el `id` técnico porque
 * Eloquent no soporta claves primarias compuestas y HorarioAtencion
 * (SE_ATIENDE_EN) debe referenciar una sola columna. No cambia el significado
 * de EJERCE ni EJERCIDA_POR: la pareja (medico_id, especialidad_id) sigue
 * siendo única.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicos_especialidades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->constrained('medicos')->restrictOnDelete();
            $table->foreignId('especialidad_id')->constrained('especialidades')->restrictOnDelete();
            $table->boolean('es_principal')->default(false);
            $table->string('estado', 30)->default('ACTIVO');

            $table->unique(['medico_id', 'especialidad_id'], 'ux_medicos_especialidades_par');
            $table->index('especialidad_id');
        });

        DB::statement("ALTER TABLE medicos_especialidades ADD CONSTRAINT ck_medicos_especialidades_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        // V3: es_principal marca la especialidad principal (solo una por médico).
        DB::statement("CREATE UNIQUE INDEX ux_medicos_especialidades_principal ON medicos_especialidades (medico_id) WHERE es_principal AND estado = 'ACTIVO'");
    }

    public function down(): void
    {
        Schema::dropIfExists('medicos_especialidades');
    }
};
