<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: TipoExamen (PEDIDO_EN hacia OrdenLaboratorio; MIDE hacia ParametroLaboratorio).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tipos_examen', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre');
            $table->string('tipo_muestra');
            $table->text('indicaciones_previas')->nullable();
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE tipos_examen ADD CONSTRAINT ck_tipos_examen_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement('CREATE UNIQUE INDEX ux_tipos_examen_nombre ON tipos_examen (lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('tipos_examen');
    }
};
