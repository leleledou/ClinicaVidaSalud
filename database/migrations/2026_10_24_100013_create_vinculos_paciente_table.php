<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: VinculoPaciente.
 *  - TIENE (composición, Paciente 1..1): paciente_id. No hay cascada: el
 *    vínculo se desactiva con `estado`.
 *  - ES_VINCULADA (Persona 1..1): persona_id es el contacto o responsable.
 *  - VERIFICA (Recepcionista 0..1): recepcionista_verifica_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vinculos_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paciente_id')->constrained('pacientes')->restrictOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->restrictOnDelete();
            $table->foreignId('recepcionista_verifica_id')->nullable()->constrained('recepcionistas')->restrictOnDelete();
            $table->string('parentesco');
            $table->boolean('es_contacto_emergencia');
            $table->boolean('es_responsable_legal');
            $table->timestamp('fecha_verificacion', 0)->nullable();
            $table->string('estado', 30)->default('ACTIVO');

            $table->index('paciente_id');
            $table->index('persona_id');
            $table->index('recepcionista_verifica_id');
        });

        DB::statement("ALTER TABLE vinculos_paciente ADD CONSTRAINT ck_vinculos_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement('ALTER TABLE vinculos_paciente ADD CONSTRAINT ck_vinculos_tipo CHECK (es_contacto_emergencia OR es_responsable_legal)');
        // La verificación registra a la recepcionista y el momento, siempre juntos.
        DB::statement('ALTER TABLE vinculos_paciente ADD CONSTRAINT ck_vinculos_verificacion CHECK ((fecha_verificacion IS NULL) = (recepcionista_verifica_id IS NULL))');
        // Un mismo contacto no se repite como vínculo activo del mismo paciente.
        DB::statement("CREATE UNIQUE INDEX ux_vinculos_paciente_persona_activo ON vinculos_paciente (paciente_id, persona_id) WHERE estado = 'ACTIVO'");
    }

    public function down(): void
    {
        Schema::dropIfExists('vinculos_paciente');
    }
};
