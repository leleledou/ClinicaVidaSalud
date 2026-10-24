<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: HistorialCita (composición de Cita, REGISTRA_CAMBIO). Registro de solo
 * inserción: sin cascada ni borrado físico (triggers en la migración 100037).
 * REALIZA (usuario 0..1: vacío solo con canal SISTEMA), FICHA_ANTERIOR y
 * FICHA_NUEVA (0..1). estado_anterior es nullable: el primer registro de una
 * cita no tiene estado previo. El número de reprogramaciones de una cita se
 * obtiene contando los cambios de ficha de este historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historiales_cita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cita_id')->constrained('citas')->restrictOnDelete();
            $table->foreignId('usuario_realiza_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('ficha_anterior_id')->nullable()->constrained('fichas')->restrictOnDelete();
            $table->foreignId('ficha_nueva_id')->nullable()->constrained('fichas')->restrictOnDelete();
            $table->string('estado_anterior', 30)->nullable();
            $table->string('estado_nuevo', 30);
            $table->string('canal', 30);
            $table->text('motivo')->nullable();
            $table->timestamp('fecha_hora', 0)->useCurrent();

            $table->index(['cita_id', 'fecha_hora'], 'ix_historiales_cita_cita_fecha');
            $table->index('usuario_realiza_id');
            $table->index('ficha_anterior_id');
            $table->index('ficha_nueva_id');
        });

        $estados = "'PROGRAMADA','CONFIRMADA','ATENDIDA','CANCELADA','INASISTENCIA'";
        DB::statement("ALTER TABLE historiales_cita ADD CONSTRAINT ck_historiales_estado_anterior CHECK (estado_anterior IS NULL OR estado_anterior IN ({$estados}))");
        DB::statement("ALTER TABLE historiales_cita ADD CONSTRAINT ck_historiales_estado_nuevo CHECK (estado_nuevo IN ({$estados}))");
        DB::statement("ALTER TABLE historiales_cita ADD CONSTRAINT ck_historiales_canal CHECK (canal IN ('WEB','VOZ','SISTEMA'))");
        DB::statement("ALTER TABLE historiales_cita ADD CONSTRAINT ck_historiales_usuario_sistema CHECK (usuario_realiza_id IS NOT NULL OR canal = 'SISTEMA')");
    }

    public function down(): void
    {
        Schema::dropIfExists('historiales_cita');
    }
};
