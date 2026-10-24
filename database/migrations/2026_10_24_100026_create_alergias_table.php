<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Alergia (composición de HistoriaClinica, ADVIERTE). La baja es lógica: `activa`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alergias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('historia_clinica_id')->constrained('historias_clinicas')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->string('sustancia');
            $table->string('reaccion')->nullable();
            $table->string('severidad', 30);
            $table->boolean('activa')->default(true);

            $table->index('historia_clinica_id');
        });

        DB::statement("ALTER TABLE alergias ADD CONSTRAINT ck_alergias_tipo CHECK (tipo IN ('MEDICAMENTO','ALIMENTO','AMBIENTAL','OTRO'))");
        DB::statement("ALTER TABLE alergias ADD CONSTRAINT ck_alergias_severidad CHECK (severidad IN ('LEVE','MODERADA','GRAVE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('alergias');
    }
};
