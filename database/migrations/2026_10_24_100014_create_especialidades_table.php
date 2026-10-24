<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Especialidad. Los límites de edad y el sexo permitido son nullable:
 * V3 los marca "por confirmar con la docente" y son configurables.
 * RF04: no se admiten dos especialidades con el mismo nombre normalizado
 * (minúsculas y sin espacios en los extremos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('especialidades', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->smallInteger('edad_minima')->nullable();
            $table->smallInteger('edad_maxima')->nullable();
            $table->string('sexo_permitido', 30)->nullable();
            $table->smallInteger('duracion_sugerida_min');
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE especialidades ADD CONSTRAINT ck_especialidades_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement("ALTER TABLE especialidades ADD CONSTRAINT ck_especialidades_sexo CHECK (sexo_permitido IS NULL OR sexo_permitido IN ('M','F'))");
        DB::statement('ALTER TABLE especialidades ADD CONSTRAINT ck_especialidades_edades CHECK ((edad_minima IS NULL OR edad_minima >= 0) AND (edad_maxima IS NULL OR edad_maxima >= 0) AND (edad_minima IS NULL OR edad_maxima IS NULL OR edad_maxima >= edad_minima))');
        DB::statement('ALTER TABLE especialidades ADD CONSTRAINT ck_especialidades_duracion CHECK (duracion_sugerida_min > 0)');
        DB::statement('CREATE UNIQUE INDEX ux_especialidades_nombre ON especialidades (lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('especialidades');
    }
};
