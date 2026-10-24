<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Notificacion. RECIBE (persona_id: va a la Persona, tenga o no cuenta).
 * referencia_tipo/referencia_id son una referencia polimórfica del UML: sin FK
 * hacia varias tablas. Al cancelar o reprogramar una cita los recordatorios
 * antiguos pasan a estado CANCELADA (no se eliminan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $table->string('tipo', 30);
            $table->string('canal', 30);
            $table->string('titulo');
            $table->text('mensaje');
            $table->string('referencia_tipo')->nullable();
            $table->unsignedBigInteger('referencia_id')->nullable();
            $table->timestamp('programada_para', 0);
            $table->timestamp('enviada_en', 0)->nullable();
            $table->timestamp('leida_en', 0)->nullable();
            $table->string('estado', 30)->default('PENDIENTE');
            $table->smallInteger('intentos')->default(0);

            $table->index('persona_id');
            $table->index(['estado', 'programada_para'], 'ix_notificaciones_estado_programada');
            $table->index(['referencia_tipo', 'referencia_id'], 'ix_notificaciones_referencia');
        });

        DB::statement("ALTER TABLE notificaciones ADD CONSTRAINT ck_notificaciones_tipo CHECK (tipo IN ('RECORDATORIO_CITA','CITA_PROGRAMADA','CITA_REPROGRAMADA','CITA_CANCELADA','RESULTADO_LISTO','VALOR_CRITICO','VERIFICAR_CORREO'))");
        DB::statement("ALTER TABLE notificaciones ADD CONSTRAINT ck_notificaciones_canal CHECK (canal IN ('CORREO','PUSH','SISTEMA'))");
        DB::statement("ALTER TABLE notificaciones ADD CONSTRAINT ck_notificaciones_estado CHECK (estado IN ('PENDIENTE','ENVIADA','FALLIDA','LEIDA','CANCELADA'))");
        DB::statement('ALTER TABLE notificaciones ADD CONSTRAINT ck_notificaciones_intentos CHECK (intentos >= 0)');
        DB::statement('ALTER TABLE notificaciones ADD CONSTRAINT ck_notificaciones_referencia CHECK ((referencia_tipo IS NULL) = (referencia_id IS NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('notificaciones');
    }
};
