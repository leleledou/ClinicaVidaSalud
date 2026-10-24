<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Antecedente (composición de HistoriaClinica, REGISTRA). La baja es lógica: `vigente`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('antecedentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('historia_clinica_id')->constrained('historias_clinicas')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->text('descripcion');
            $table->date('fecha_inicio')->nullable();
            $table->boolean('vigente')->default(true);

            $table->index('historia_clinica_id');
        });

        DB::statement("ALTER TABLE antecedentes ADD CONSTRAINT ck_antecedentes_tipo CHECK (tipo IN ('PATOLOGICO','CRONICO','QUIRURGICO','FAMILIAR','GINECO_OBSTETRICO','HABITO','MEDICACION_ACTUAL'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('antecedentes');
    }
};
