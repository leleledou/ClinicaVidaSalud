<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Bitacora (GENERA, usuario 0..1: las operaciones automáticas no tienen
 * usuario). Auditoría de solo inserción (los triggers de bloqueo se crean en
 * 2026_10_24_100037_create_integrity_triggers).
 *
 * tabla_afectada/registro_id son una referencia polimórfica del UML: sin FK.
 * datos_anteriores/datos_nuevos no deben contener contraseñas ni secretos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacoras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->nullable()->constrained('usuarios')->restrictOnDelete();
            $table->string('accion');
            $table->string('modulo');
            $table->string('tabla_afectada')->nullable();
            $table->unsignedBigInteger('registro_id')->nullable();
            $table->string('canal', 30);
            $table->text('transcripcion_voz')->nullable();
            $table->jsonb('datos_anteriores')->nullable();
            $table->jsonb('datos_nuevos')->nullable();
            $table->boolean('exito');
            $table->string('ip', 45)->nullable();
            $table->timestamp('fecha_hora', 0)->useCurrent();

            $table->index(['tabla_afectada', 'registro_id'], 'ix_bitacoras_registro');
            $table->index(['usuario_id', 'fecha_hora'], 'ix_bitacoras_usuario_fecha');
            $table->index('fecha_hora');
        });

        DB::statement("ALTER TABLE bitacoras ADD CONSTRAINT ck_bitacoras_canal CHECK (canal IN ('WEB','VOZ','SISTEMA'))");
        DB::statement("ALTER TABLE bitacoras ADD CONSTRAINT ck_bitacoras_voz CHECK (transcripcion_voz IS NULL OR canal = 'VOZ')");
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacoras');
    }
};
