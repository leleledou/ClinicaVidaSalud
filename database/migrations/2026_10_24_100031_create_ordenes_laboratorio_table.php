<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: OrdenLaboratorio. SOLICITA (consulta_id) y PEDIDO_EN (tipo_examen_id):
 * una orden corresponde a un solo tipo de examen. La edad del paciente para
 * elegir el rango se calcula a fecha_toma_muestra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ordenes_laboratorio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consulta_id')->constrained('consultas')->restrictOnDelete();
            $table->foreignId('tipo_examen_id')->constrained('tipos_examen')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->timestamp('fecha_solicitud', 0);
            $table->timestamp('fecha_toma_muestra', 0)->nullable();
            $table->string('prioridad', 30)->default('NORMAL');
            $table->string('estado', 30)->default('SOLICITADA');
            $table->string('laboratorio_procesador')->nullable();
            $table->string('archivo_resultado')->nullable();
            $table->timestamp('fecha_revision', 0)->nullable();

            $table->index('consulta_id');
            $table->index('tipo_examen_id');
            $table->index('estado');
        });

        DB::statement("ALTER TABLE ordenes_laboratorio ADD CONSTRAINT ck_ordenes_prioridad CHECK (prioridad IN ('NORMAL','URGENTE'))");
        DB::statement("ALTER TABLE ordenes_laboratorio ADD CONSTRAINT ck_ordenes_estado CHECK (estado IN ('SOLICITADA','MUESTRA_TOMADA','RESULTADO_LISTO','REVISADA','ANULADA'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('ordenes_laboratorio');
    }
};
