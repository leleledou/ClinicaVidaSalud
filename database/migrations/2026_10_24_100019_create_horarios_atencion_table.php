<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: HorarioAtencion. SE_ATIENDE_EN (medico_especialidad_id: el horario solo
 * puede crearse para una especialidad que el médico tiene) y UBICA
 * (consultorio_id, 0..1).
 *
 * Restricciones de esta tabla: día, horas, duración, vigencia y modalidad.
 * El no solapamiento por consultorio (EXCLUDE) y por médico (trigger) se
 * crean en las migraciones 100036 y 100037, cuando existen todas las tablas.
 * Para cambiar un horario se cierra la vigencia y se crea otro (V3); la
 * duración debe dividir exactamente el intervalo (RN08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horarios_atencion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_especialidad_id')->constrained('medicos_especialidades')->restrictOnDelete();
            $table->foreignId('consultorio_id')->nullable()->constrained('consultorios')->restrictOnDelete();
            $table->smallInteger('dia_semana'); // 1 = lunes ... 7 = domingo
            $table->time('hora_inicio', 0);
            $table->time('hora_fin', 0);
            $table->smallInteger('duracion_ficha_min');
            $table->string('modalidad', 30);
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable(); // NULL = vigencia abierta
            $table->string('estado', 30)->default('ACTIVO');

            $table->index('medico_especialidad_id');
            $table->index('consultorio_id');
        });

        DB::statement("ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement("ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_modalidad CHECK (modalidad IN ('PRESENCIAL','VIRTUAL'))");
        DB::statement('ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_dia CHECK (dia_semana BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_horas CHECK (hora_fin > hora_inicio)');
        DB::statement('ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_duracion CHECK (duracion_ficha_min > 0)');
        DB::statement('ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_vigencia CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
        // RN08: la duración de la ficha divide exactamente el intervalo del horario.
        DB::statement('ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_duracion_divide CHECK ((EXTRACT(EPOCH FROM (hora_fin - hora_inicio))::int % NULLIF(duracion_ficha_min * 60, 0)) = 0)');
        // V3: un horario presencial ocurre en un consultorio; uno virtual no tiene.
        DB::statement("ALTER TABLE horarios_atencion ADD CONSTRAINT ck_horarios_modalidad_consultorio CHECK ((modalidad = 'PRESENCIAL') = (consultorio_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('horarios_atencion');
    }
};
