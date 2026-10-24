<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: RangoReferencia (composición de ParametroLaboratorio, SE_EVALUA_CON).
 * sexo NULL = aplica a ambos (un rango específico por sexo tiene prioridad al
 * interpretar). Un rango ya usado no se edita: se cierra su vigencia y se crea
 * otra versión (trigger en la migración 100037). El no solapamiento de rangos
 * por parámetro, sexo, edad y vigencia es un EXCLUDE (migración 100036).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rangos_referencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parametro_laboratorio_id')->constrained('parametros_laboratorio')->restrictOnDelete();
            $table->integer('edad_min_meses');
            $table->integer('edad_max_meses');
            $table->string('sexo', 30)->nullable();
            $table->decimal('valor_min', 12, 4)->nullable();
            $table->decimal('valor_max', 12, 4)->nullable();
            $table->decimal('critico_min', 12, 4)->nullable();
            $table->decimal('critico_max', 12, 4)->nullable();
            $table->date('vigente_desde');
            $table->date('vigente_hasta')->nullable();

            $table->index('parametro_laboratorio_id');
        });

        DB::statement("ALTER TABLE rangos_referencia ADD CONSTRAINT ck_rangos_sexo CHECK (sexo IS NULL OR sexo IN ('M','F'))");
        DB::statement('ALTER TABLE rangos_referencia ADD CONSTRAINT ck_rangos_edades CHECK (edad_min_meses >= 0 AND edad_max_meses >= edad_min_meses)');
        DB::statement('ALTER TABLE rangos_referencia ADD CONSTRAINT ck_rangos_vigencia CHECK (vigente_hasta IS NULL OR vigente_hasta >= vigente_desde)');
        DB::statement(<<<'SQL'
            ALTER TABLE rangos_referencia ADD CONSTRAINT ck_rangos_valores CHECK (
                    (valor_min IS NULL OR valor_max IS NULL OR valor_max >= valor_min)
                AND (critico_min IS NULL OR valor_min IS NULL OR critico_min <= valor_min)
                AND (critico_max IS NULL OR valor_max IS NULL OR critico_max >= valor_max)
                AND (critico_min IS NULL OR critico_max IS NULL OR critico_max >= critico_min)
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('rangos_referencia');
    }
};
