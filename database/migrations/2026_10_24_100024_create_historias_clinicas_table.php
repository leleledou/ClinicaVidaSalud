<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: HistoriaClinica (composición de Paciente, POSEE 1..1): una sola historia
 * por paciente (paciente_id UNIQUE). Los registros clínicos no se eliminan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historias_clinicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->unique()->constrained('pacientes')->restrictOnDelete();
            $table->date('fecha_apertura');
            $table->string('grupo_sanguineo', 30)->default('DESCONOCIDO');
            $table->text('observaciones')->nullable();
        });

        DB::statement("ALTER TABLE historias_clinicas ADD CONSTRAINT ck_historias_grupo_sanguineo CHECK (grupo_sanguineo IN ('A_POS','A_NEG','B_POS','B_NEG','AB_POS','AB_NEG','O_POS','O_NEG','DESCONOCIDO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('historias_clinicas');
    }
};
