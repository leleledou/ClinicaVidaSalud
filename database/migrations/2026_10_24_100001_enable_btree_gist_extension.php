<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * btree_gist permite combinar igualdad (=) y solapamiento (&&) en restricciones
 * EXCLUDE (horarios por consultorio y rangos de referencia, ver
 * 2026_10_24_100036_add_exclusion_constraints). Desde PostgreSQL 13 es una
 * extensión "trusted": la puede crear el dueño de la base de datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
    }

    public function down(): void
    {
        // Se revierte después de las restricciones EXCLUDE que dependen de ella.
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }
};
