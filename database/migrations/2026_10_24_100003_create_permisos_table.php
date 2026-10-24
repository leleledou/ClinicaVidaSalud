<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// UML: Permiso (OTORGA hacia Rol).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->string('nombre');
            $table->string('modulo');
        });

        // V3: nombre único dentro del catálogo.
        DB::statement('CREATE UNIQUE INDEX ux_permisos_nombre ON permisos (lower(btrim(nombre)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('permisos');
    }
};
