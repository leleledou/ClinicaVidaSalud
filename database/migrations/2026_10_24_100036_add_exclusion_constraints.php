<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Restricciones EXCLUDE (requieren btree_gist, migración 100001).
 *
 *  1. Un consultorio no se usa dos veces a la misma hora (V3 §10.1): para un
 *     mismo consultorio y día de la semana no pueden solaparse ni las
 *     vigencias ni las franjas horarias de horarios ACTIVOS. Los horarios
 *     virtuales (sin consultorio) quedan fuera.
 *  2. Rangos de referencia sin ambigüedad (C09): para un mismo parámetro y
 *     sexo (NULL = ambos) no pueden solaparse la edad en meses ni la vigencia.
 *
 * El no solapamiento de horarios por MÉDICO no puede ser EXCLUDE sin una
 * columna medico_id redundante (no aprobada): se resuelve con trigger en la
 * migración 100037.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE horarios_atencion ADD CONSTRAINT ex_horarios_consultorio EXCLUDE USING gist (
                consultorio_id WITH =,
                dia_semana WITH =,
                daterange(vigente_desde, vigente_hasta, '[]') WITH &&,
                tsrange(date '2000-01-01' + hora_inicio, date '2000-01-01' + hora_fin) WITH &&
            ) WHERE (consultorio_id IS NOT NULL AND estado = 'ACTIVO')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE rangos_referencia ADD CONSTRAINT ex_rangos_referencia_solape EXCLUDE USING gist (
                parametro_laboratorio_id WITH =,
                (COALESCE(sexo, '*')::text) WITH =,
                int4range(edad_min_meses, edad_max_meses, '[]') WITH &&,
                daterange(vigente_desde, vigente_hasta, '[]') WITH &&
            )
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rangos_referencia DROP CONSTRAINT IF EXISTS ex_rangos_referencia_solape');
        DB::statement('ALTER TABLE horarios_atencion DROP CONSTRAINT IF EXISTS ex_horarios_consultorio');
    }
};
