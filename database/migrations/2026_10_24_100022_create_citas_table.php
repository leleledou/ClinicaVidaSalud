<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Cita.
 *  - REGISTRA (usuario_registra_id), SOLICITA (paciente_id), OCUPADA_POR
 *    (ficha_id), CUBRE (seguro_paciente_id, 0..1), AUTORIZA_EXCEPCION
 *    (administrador_autoriza_id, 0..1), REEMPLAZA (cita_reemplazada_id, 0..1).
 *  - NO existe medico_id: /ATIENDE es derivada (Cita → Ficha → HorarioAtencion
 *    → MedicoEspecialidad → Medico; futuro Cita::medicoAsignado()).
 *  - `origen` está en el UML V3.1 pero no en el documento V3: se conserva como
 *    texto nullable sin catálogo de valores (pendiente de definición).
 *
 * Disponibilidad: ux_citas_ficha_activa admite varias citas históricas por
 * ficha pero una sola cuyo estado no sea CANCELADA. Las reservas deben
 * hacerse en una transacción con Ficha::lockForUpdate(); el índice es el
 * árbitro final ante concurrencia (SQLSTATE 23505).
 *
 * El reemplazo exige además (trigger, migración 100037) que la cita anterior
 * esté CANCELADA y sea del mismo paciente, y que el vínculo sea inmutable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_registra_id')->constrained('usuarios')->restrictOnDelete();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('ficha_id')->constrained('fichas')->restrictOnDelete();
            $table->foreignId('seguro_paciente_id')->nullable()->constrained('seguros_paciente')->restrictOnDelete();
            $table->foreignId('administrador_autoriza_id')->nullable()->constrained('administradores')->restrictOnDelete();
            $table->foreignId('cita_reemplazada_id')->nullable()->unique()->constrained('citas')->restrictOnDelete();
            $table->string('codigo', 30)->unique();
            $table->string('origen', 30)->nullable();
            $table->string('canal', 30);
            $table->text('motivo')->nullable();
            $table->string('estado', 30)->default('PROGRAMADA');
            $table->string('codigo_autorizacion_seguro')->nullable();
            $table->string('enlace_virtual')->nullable();
            $table->text('motivo_cancelacion')->nullable();
            $table->text('justificacion_excepcion')->nullable();
            $table->timestamp('fecha_registro', 0)->useCurrent();

            $table->index('usuario_registra_id');
            $table->index(['paciente_id', 'estado'], 'ix_citas_paciente_estado');
            $table->index('ficha_id');
            $table->index('seguro_paciente_id');
            $table->index('administrador_autoriza_id');
            $table->index('fecha_registro');
        });

        DB::statement("ALTER TABLE citas ADD CONSTRAINT ck_citas_estado CHECK (estado IN ('PROGRAMADA','CONFIRMADA','ATENDIDA','CANCELADA','INASISTENCIA'))");
        // V3 (Cita.canal): se registra por WEB o VOZ; SISTEMA solo aplica a historial y bitácora.
        DB::statement("ALTER TABLE citas ADD CONSTRAINT ck_citas_canal CHECK (canal IN ('WEB','VOZ'))");
        // RF09: el motivo de cancelación es obligatorio al cancelar.
        DB::statement("ALTER TABLE citas ADD CONSTRAINT ck_citas_cancelacion CHECK (estado <> 'CANCELADA' OR (motivo_cancelacion IS NOT NULL AND btrim(motivo_cancelacion) <> ''))");
        // La excepción de edad la autoriza un administrador con justificación, siempre juntos.
        DB::statement('ALTER TABLE citas ADD CONSTRAINT ck_citas_excepcion CHECK ((administrador_autoriza_id IS NULL) = (justificacion_excepcion IS NULL))');
        DB::statement('ALTER TABLE citas ADD CONSTRAINT ck_citas_no_autoreemplazo CHECK (cita_reemplazada_id IS NULL OR cita_reemplazada_id <> id)');
        // Una ficha solo puede tener una cita cuyo estado sea distinto de CANCELADA.
        DB::statement("CREATE UNIQUE INDEX ux_citas_ficha_activa ON citas (ficha_id) WHERE estado <> 'CANCELADA'");
    }

    public function down(): void
    {
        Schema::dropIfExists('citas');
    }
};
