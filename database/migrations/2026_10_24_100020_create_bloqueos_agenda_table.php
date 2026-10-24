<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: BloqueoAgenda. AUSENCIA_DE (medico_id, 0..1) y CIERRA (consultorio_id,
 * 0..1). El alcance determina cuál de las dos FK es obligatoria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloqueos_agenda', function (Blueprint $table) {
            $table->id();
            $table->string('alcance', 30);
            $table->foreignId('medico_id')->nullable()->constrained('medicos')->restrictOnDelete();
            $table->foreignId('consultorio_id')->nullable()->constrained('consultorios')->restrictOnDelete();
            $table->timestamp('inicio', 0);
            $table->timestamp('fin', 0);
            $table->text('motivo');

            $table->index('medico_id');
            $table->index('consultorio_id');
            $table->index(['inicio', 'fin']);
        });

        DB::statement("ALTER TABLE bloqueos_agenda ADD CONSTRAINT ck_bloqueos_alcance_valido CHECK (alcance IN ('CLINICA','CONSULTORIO','MEDICO'))");
        DB::statement(<<<'SQL'
            ALTER TABLE bloqueos_agenda ADD CONSTRAINT ck_bloqueos_alcance_destino CHECK (
                   (alcance = 'CLINICA'     AND medico_id IS NULL     AND consultorio_id IS NULL)
                OR (alcance = 'CONSULTORIO' AND consultorio_id IS NOT NULL AND medico_id IS NULL)
                OR (alcance = 'MEDICO'      AND medico_id IS NOT NULL AND consultorio_id IS NULL)
            )
            SQL);
        DB::statement('ALTER TABLE bloqueos_agenda ADD CONSTRAINT ck_bloqueos_rango CHECK (fin > inicio)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueos_agenda');
    }
};
