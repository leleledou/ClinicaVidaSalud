<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Consultorio (UBICA hacia HorarioAtencion, CIERRA hacia BloqueoAgenda).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultorios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('ubicacion');
            $table->string('estado', 30)->default('ACTIVO');
        });

        DB::statement("ALTER TABLE consultorios ADD CONSTRAINT ck_consultorios_estado CHECK (estado IN ('ACTIVO','INACTIVO'))");
        DB::statement('CREATE UNIQUE INDEX ux_consultorios_nombre ON consultorios (lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('consultorios');
    }
};
