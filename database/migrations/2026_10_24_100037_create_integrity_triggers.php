<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Funciones y triggers de integridad de PostgreSQL. Se crean al final porque
 * dependen de que existan todas las tablas.
 *
 *  - Sin borrado físico (RN11, RF09): DELETE bloqueado en las tablas que
 *    conservan historial. La baja es lógica por `estado`/`vigente`/`activa`.
 *  - Solo inserción: bitacoras e historiales_cita no admiten UPDATE.
 *  - Reemplazo de cita: la cita anterior debe estar CANCELADA y ser del mismo
 *    paciente, y el vínculo cita_reemplazada_id es inmutable.
 *  - Consulta FINALIZADA no se modifica (RN10).
 *  - Un rango de referencia ya usado por un resultado no se edita: solo se
 *    puede cerrar su vigencia (C09).
 *  - Un médico no puede tener dos horarios activos que se crucen (V3 §10.1).
 *
 * Reglas NO cubiertas aquí (quedan para la lógica de negocio, no omitidas):
 * paciente sin dos citas cruzadas, seguro del mismo paciente/verificado/vigente,
 * transiciones de estado de la cita, máximo de reprogramaciones, estados de la
 * cita desde los que puede abrirse una consulta.
 */
return new class extends Migration
{
    /** Tablas con historial: sin DELETE físico. */
    private const SIN_DELETE = [
        'bitacoras', 'historiales_cita', 'citas', 'consultas', 'historias_clinicas',
        'antecedentes', 'alergias', 'tratamientos', 'prescripciones',
        'ordenes_laboratorio', 'resultados_laboratorio', 'seguros_paciente',
        'vinculos_paciente', 'pacientes', 'medicos', 'recepcionistas',
        'administradores', 'personas', 'usuarios',
    ];

    /** Tablas de solo inserción: sin UPDATE. */
    private const SOLO_INSERCION = ['bitacoras', 'historiales_cita'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_bloquear_delete() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Eliminación física no permitida en %: use la baja lógica', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END
            $$
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_bloquear_update() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La tabla % es de solo inserción', TG_TABLE_NAME
                    USING ERRCODE = 'restrict_violation';
            END
            $$
            SQL);

        foreach (self::SIN_DELETE as $tabla) {
            DB::unprepared("CREATE TRIGGER tg_{$tabla}_no_delete BEFORE DELETE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION fn_bloquear_delete()");
        }
        foreach (self::SOLO_INSERCION as $tabla) {
            DB::unprepared("CREATE TRIGGER tg_{$tabla}_no_update BEFORE UPDATE ON {$tabla} FOR EACH ROW EXECUTE FUNCTION fn_bloquear_update()");
        }

        // REEMPLAZA: la nueva cita referencia a la cancelada anterior.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_validar_reemplazo_cita() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_prev citas%ROWTYPE;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.cita_reemplazada_id IS DISTINCT FROM OLD.cita_reemplazada_id THEN
                        RAISE EXCEPTION 'cita_reemplazada_id es inmutable'
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.cita_reemplazada_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT * INTO v_prev FROM citas WHERE id = NEW.cita_reemplazada_id;
                IF v_prev.estado <> 'CANCELADA' THEN
                    RAISE EXCEPTION 'La cita reemplazada (%) debe estar CANCELADA', v_prev.id
                        USING ERRCODE = 'check_violation';
                END IF;
                IF v_prev.paciente_id <> NEW.paciente_id THEN
                    RAISE EXCEPTION 'La cita reemplazada debe ser del mismo paciente'
                        USING ERRCODE = 'check_violation';
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        DB::unprepared('CREATE TRIGGER tg_citas_reemplazo BEFORE INSERT OR UPDATE ON citas FOR EACH ROW EXECUTE FUNCTION fn_validar_reemplazo_cita()');

        // RN10: una consulta FINALIZADA no se modifica.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_proteger_consulta_finalizada() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.estado = 'FINALIZADA' THEN
                    RAISE EXCEPTION 'La consulta % está FINALIZADA y no puede modificarse', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        DB::unprepared('CREATE TRIGGER tg_consultas_finalizada_inmutable BEFORE UPDATE ON consultas FOR EACH ROW EXECUTE FUNCTION fn_proteger_consulta_finalizada()');

        // C09: un rango usado no se edita; solo se cierra su vigencia.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_proteger_rango_usado() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM resultados_laboratorio WHERE rango_referencia_id = OLD.id) THEN
                    IF (NEW.parametro_laboratorio_id, NEW.edad_min_meses, NEW.edad_max_meses, NEW.sexo,
                        NEW.valor_min, NEW.valor_max, NEW.critico_min, NEW.critico_max, NEW.vigente_desde)
                       IS DISTINCT FROM
                       (OLD.parametro_laboratorio_id, OLD.edad_min_meses, OLD.edad_max_meses, OLD.sexo,
                        OLD.valor_min, OLD.valor_max, OLD.critico_min, OLD.critico_max, OLD.vigente_desde) THEN
                        RAISE EXCEPTION 'El rango % ya fue usado: cierre su vigencia y cree una nueva versión', OLD.id
                            USING ERRCODE = 'restrict_violation';
                    END IF;
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        DB::unprepared('CREATE TRIGGER tg_rangos_referencia_usado BEFORE UPDATE ON rangos_referencia FOR EACH ROW EXECUTE FUNCTION fn_proteger_rango_usado()');

        // Un médico no tiene dos horarios activos cruzados (aunque sean de distinta especialidad).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fn_validar_solape_horario_medico() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                v_medico bigint;
            BEGIN
                IF NEW.estado <> 'ACTIVO' THEN
                    RETURN NEW;
                END IF;

                SELECT medico_id INTO v_medico
                FROM medicos_especialidades WHERE id = NEW.medico_especialidad_id;

                -- Serializa las altas/cambios de horarios del mismo médico.
                PERFORM pg_advisory_xact_lock(7101, (v_medico % 2147483647)::int);

                IF EXISTS (
                    SELECT 1
                    FROM horarios_atencion h
                    JOIN medicos_especialidades me ON me.id = h.medico_especialidad_id
                    WHERE me.medico_id = v_medico
                      AND h.id IS DISTINCT FROM NEW.id
                      AND h.estado = 'ACTIVO'
                      AND h.dia_semana = NEW.dia_semana
                      AND daterange(h.vigente_desde, h.vigente_hasta, '[]')
                          && daterange(NEW.vigente_desde, NEW.vigente_hasta, '[]')
                      AND h.hora_inicio < NEW.hora_fin
                      AND NEW.hora_inicio < h.hora_fin
                ) THEN
                    RAISE EXCEPTION 'El médico ya tiene un horario activo que se cruza con este'
                        USING ERRCODE = 'exclusion_violation';
                END IF;
                RETURN NEW;
            END
            $$
            SQL);
        DB::unprepared('CREATE TRIGGER tg_horarios_solape_medico BEFORE INSERT OR UPDATE ON horarios_atencion FOR EACH ROW EXECUTE FUNCTION fn_validar_solape_horario_medico()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS tg_horarios_solape_medico ON horarios_atencion');
        DB::unprepared('DROP TRIGGER IF EXISTS tg_rangos_referencia_usado ON rangos_referencia');
        DB::unprepared('DROP TRIGGER IF EXISTS tg_consultas_finalizada_inmutable ON consultas');
        DB::unprepared('DROP TRIGGER IF EXISTS tg_citas_reemplazo ON citas');
        foreach (self::SOLO_INSERCION as $tabla) {
            DB::unprepared("DROP TRIGGER IF EXISTS tg_{$tabla}_no_update ON {$tabla}");
        }
        foreach (self::SIN_DELETE as $tabla) {
            DB::unprepared("DROP TRIGGER IF EXISTS tg_{$tabla}_no_delete ON {$tabla}");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS fn_validar_solape_horario_medico()');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_proteger_rango_usado()');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_proteger_consulta_finalizada()');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_validar_reemplazo_cita()');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bloquear_update()');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_bloquear_delete()');
    }
};
