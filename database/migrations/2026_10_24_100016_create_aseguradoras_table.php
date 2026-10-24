<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Aseguradora (EMITE hacia PacienteSeguro).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aseguradoras', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('nit', 30)->unique();
            $table->string('telefono', 30)->nullable();
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE aseguradoras ADD CONSTRAINT ck_aseguradoras_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement('CREATE UNIQUE INDEX ux_aseguradoras_nombre ON aseguradoras (lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('aseguradoras');
    }
};
