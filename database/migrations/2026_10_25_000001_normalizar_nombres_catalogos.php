<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * RF04 y CU06 paso 5: el nombre de una especialidad (y de un consultorio, CU08
 * paso 4) es único sin importar tildes, mayúsculas ni espacios repetidos.
 * «Pediatría», « pediatria » y «PEDIATRÍA» son el mismo nombre.
 *
 * fn_normalizar_texto es IMMUTABLE para poder usarse en un índice. Se llama a
 * unaccent con el diccionario explícito (forma de dos argumentos), que sí es
 * estable aunque cambie el search_path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_normalizar_texto(texto text) RETURNS text
            LANGUAGE sql IMMUTABLE PARALLEL SAFE AS
            $$ SELECT lower(regexp_replace(btrim(public.unaccent('public.unaccent'::regdictionary, texto)), '\s+', ' ', 'g')) $$
            SQL);

        // Los índices únicos pasan de lower(btrim(nombre)) al nombre normalizado.
        DB::statement('DROP INDEX ux_especialidades_nombre');
        DB::statement('CREATE UNIQUE INDEX ux_especialidades_nombre ON especialidades (fn_normalizar_texto(nombre))');

        DB::statement('DROP INDEX ux_consultorios_nombre');
        DB::statement('CREATE UNIQUE INDEX ux_consultorios_nombre ON consultorios (fn_normalizar_texto(nombre))');
    }

    public function down(): void
    {
        // Se restauran los índices originales. La extensión unaccent no se borra.
        DB::statement('DROP INDEX ux_especialidades_nombre');
        DB::statement('CREATE UNIQUE INDEX ux_especialidades_nombre ON especialidades (lower(btrim(nombre)))');

        DB::statement('DROP INDEX ux_consultorios_nombre');
        DB::statement('CREATE UNIQUE INDEX ux_consultorios_nombre ON consultorios (lower(btrim(nombre)))');

        DB::statement('DROP FUNCTION IF EXISTS fn_normalizar_texto(text)');
    }
};
