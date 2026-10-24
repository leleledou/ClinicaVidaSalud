<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: ResultadoLaboratorio. PRODUCE (composición de OrdenLaboratorio),
 * MEDIDO_EN (parámetro), APLICADO_A (rango, 0..1: se conserva aunque luego
 * cambien los rangos) y CARGA (usuario que cargó el resultado).
 * Un resultado por parámetro y orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados_laboratorio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_laboratorio_id')->constrained('ordenes_laboratorio')->restrictOnDelete();
            $table->foreignId('parametro_laboratorio_id')->constrained('parametros_laboratorio')->restrictOnDelete();
            $table->foreignId('rango_referencia_id')->nullable()->constrained('rangos_referencia')->restrictOnDelete();
            $table->foreignId('usuario_carga_id')->constrained('usuarios')->restrictOnDelete();
            $table->decimal('valor_numerico', 12, 4)->nullable();
            $table->text('valor_texto')->nullable();
            $table->string('interpretacion', 30)->default('NO_APLICA');
            $table->timestamp('fecha_resultado', 0);
            $table->text('observacion')->nullable();

            $table->unique(['orden_laboratorio_id', 'parametro_laboratorio_id'], 'ux_resultados_orden_parametro');
            $table->index('parametro_laboratorio_id');
            $table->index('rango_referencia_id');
            $table->index('usuario_carga_id');
        });

        DB::statement("ALTER TABLE resultados_laboratorio ADD CONSTRAINT ck_resultados_interpretacion CHECK (interpretacion IN ('NORMAL','BAJO','ALTO','CRITICO_BAJO','CRITICO_ALTO','NO_APLICA'))");
        DB::statement('ALTER TABLE resultados_laboratorio ADD CONSTRAINT ck_resultados_valor CHECK (valor_numerico IS NOT NULL OR valor_texto IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados_laboratorio');
    }
};
