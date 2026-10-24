<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Prescripcion (composición de Tratamiento, PRESCRIBE). Impedir recetar un
 * medicamento al que el paciente tiene una alergia activa es regla de negocio
 * (servicio), no de esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescripciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tratamiento_id')->constrained('tratamientos')->restrictOnDelete();
            $table->string('medicamento');
            $table->string('dosis');
            $table->string('frecuencia');
            $table->smallInteger('duracion_dias');
            $table->string('via');

            $table->index('tratamiento_id');
        });

        DB::statement('ALTER TABLE prescripciones ADD CONSTRAINT ck_prescripciones_duracion CHECK (duracion_dias > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescripciones');
    }
};
