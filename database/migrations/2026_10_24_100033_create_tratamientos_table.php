<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Tratamiento. INDICA (composición de Consulta) y ORIGINA (resultado de
 * laboratorio que lo originó, 0..1). El resultado debe ser del mismo paciente:
 * regla de negocio (servicio).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tratamientos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas')->restrictOnDelete();
            $table->foreignId('resultado_laboratorio_id')->nullable()->constrained('resultados_laboratorio')->restrictOnDelete();
            $table->text('descripcion');
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();
            $table->string('estado', 30)->default('EN_CURSO');
            $table->string('respuesta', 30)->default('PENDIENTE');
            $table->text('observacion_evolucion')->nullable();

            $table->index('consulta_id');
            $table->index('resultado_laboratorio_id');
        });

        DB::statement("ALTER TABLE tratamientos ADD CONSTRAINT ck_tratamientos_estado CHECK (estado IN ('EN_CURSO','FINALIZADO','SUSPENDIDO'))");
        DB::statement("ALTER TABLE tratamientos ADD CONSTRAINT ck_tratamientos_respuesta CHECK (respuesta IN ('PENDIENTE','MEJORO','SIN_CAMBIO','EMPEORO'))");
        DB::statement('ALTER TABLE tratamientos ADD CONSTRAINT ck_tratamientos_fechas CHECK (fecha_fin IS NULL OR fecha_fin >= fecha_inicio)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tratamientos');
    }
};
