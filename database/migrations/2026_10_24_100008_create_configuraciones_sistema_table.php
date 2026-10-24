<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UML: ConfiguracionSistema. Parámetros clave/valor sin relaciones; `clave` es
 * la clave primaria (sin id técnico). tipo_dato no es una de las 23
 * enumeraciones de V3: sus valores (int, string, bool, decimal) salen de la
 * descripción del atributo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuraciones_sistema', function (Blueprint $table) {
            $table->string('clave')->primary();
            $table->text('valor');
            $table->string('tipo_dato', 20);
            $table->text('descripcion')->nullable();
        });

        DB::statement("ALTER TABLE configuraciones_sistema ADD CONSTRAINT ck_configuraciones_tipo_dato CHECK (tipo_dato IN ('int','string','bool','decimal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('configuraciones_sistema');
    }
};
