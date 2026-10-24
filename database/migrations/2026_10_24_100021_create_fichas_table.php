<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Ficha (GENERA desde HorarioAtencion). El atributo derivado /ocupada NO
 * se almacena: se calcula con la existencia de una cita no cancelada
 * (ver índice ux_citas_ficha_activa en `citas`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fichas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('horario_atencion_id')->constrained('horarios_atencion')->restrictOnDelete();
            $table->date('fecha');
            $table->time('hora_inicio', 0);
            $table->time('hora_fin', 0);
            $table->string('estado', 30)->default('HABILITADA');

            // Evita generar dos veces la misma ficha de un horario.
            $table->unique(['horario_atencion_id', 'fecha', 'hora_inicio'], 'ux_fichas_horario_fecha_hora');
            $table->index(['fecha', 'hora_inicio'], 'ix_fichas_fecha_hora');
        });

        DB::statement("ALTER TABLE fichas ADD CONSTRAINT ck_fichas_estado CHECK (estado IN ('HABILITADA','BLOQUEADA'))");
        DB::statement('ALTER TABLE fichas ADD CONSTRAINT ck_fichas_horas CHECK (hora_fin > hora_inicio)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fichas');
    }
};
