<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: Consulta (SE_ATIENDE_EN: una cita tiene como máximo una consulta).
 *
 * DECISIÓN PENDIENTE (regla de negocio): desde qué estados de la cita puede
 * iniciarse una consulta. V3 §10.3 dice "solo existe para una cita ATENDIDA"
 * pero también admite consultas EN_CURSO antes de marcar la cita; por eso la
 * base de datos NO restringe el estado de la cita y lo decidirá el servicio.
 *
 * Todo el contenido clínico es nullable porque una consulta EN_CURSO se puede
 * guardar parcialmente. Una consulta FINALIZADA no se modifica (trigger en la
 * migración 100037).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cita_id')->unique()->constrained('citas')->restrictOnDelete();
            $table->string('estado', 30)->default('EN_CURSO');
            $table->timestamp('inicio', 0);
            $table->timestamp('fin', 0)->nullable();
            $table->text('anamnesis')->nullable();
            $table->text('examen_fisico')->nullable();
            $table->decimal('peso_kg', 5, 2)->nullable();
            $table->decimal('talla_cm', 5, 2)->nullable();
            $table->smallInteger('presion_sistolica')->nullable();
            $table->smallInteger('presion_diastolica')->nullable();
            $table->decimal('temperatura_c', 4, 1)->nullable();
            $table->smallInteger('frecuencia_cardiaca')->nullable();
            $table->smallInteger('saturacion_o2')->nullable();
            $table->string('codigo_cie10', 10)->nullable();
            $table->text('diagnostico')->nullable();
            $table->text('indicaciones')->nullable();
        });

        DB::statement("ALTER TABLE consultas ADD CONSTRAINT ck_consultas_estado CHECK (estado IN ('EN_CURSO','FINALIZADA'))");
        DB::statement('ALTER TABLE consultas ADD CONSTRAINT ck_consultas_fin CHECK (fin IS NULL OR fin >= inicio)');
        DB::statement("ALTER TABLE consultas ADD CONSTRAINT ck_consultas_finalizada_con_fin CHECK (estado <> 'FINALIZADA' OR fin IS NOT NULL)");
        DB::statement(<<<'SQL'
            ALTER TABLE consultas ADD CONSTRAINT ck_consultas_signos_vitales CHECK (
                    (peso_kg IS NULL OR peso_kg > 0)
                AND (talla_cm IS NULL OR talla_cm > 0)
                AND (presion_sistolica IS NULL OR presion_sistolica > 0)
                AND (presion_diastolica IS NULL OR presion_diastolica > 0)
                AND (presion_sistolica IS NULL OR presion_diastolica IS NULL OR presion_sistolica >= presion_diastolica)
                AND (temperatura_c IS NULL OR temperatura_c > 0)
                AND (frecuencia_cardiaca IS NULL OR frecuencia_cardiaca > 0)
                AND (saturacion_o2 IS NULL OR saturacion_o2 BETWEEN 0 AND 100)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('consultas');
    }
};
