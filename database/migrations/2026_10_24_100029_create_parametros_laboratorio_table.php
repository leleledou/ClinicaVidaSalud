<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: ParametroLaboratorio (composición de TipoExamen, MIDE 1..*). El mínimo
 * de un parámetro por examen (1..*) se valida en la lógica de alta del examen.
 * V3 dice "nombre único dentro del catálogo": se aplica por tipo de examen,
 * que es la lectura menos restrictiva compatible con la composición.
 * `unidad` es nullable porque los parámetros de tipo TEXTO no la necesitan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametros_laboratorio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_examen_id')->constrained('tipos_examen')->restrictOnDelete();
            $table->string('nombre');
            $table->string('unidad')->nullable();
            $table->string('tipo_valor', 30);
            $table->smallInteger('orden');

            $table->unique(['tipo_examen_id', 'orden'], 'ux_parametros_examen_orden');
        });

        DB::statement("ALTER TABLE parametros_laboratorio ADD CONSTRAINT ck_parametros_tipo_valor CHECK (tipo_valor IN ('NUMERICO','TEXTO'))");
        DB::statement('CREATE UNIQUE INDEX ux_parametros_examen_nombre ON parametros_laboratorio (tipo_examen_id, lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros_laboratorio');
    }
};
