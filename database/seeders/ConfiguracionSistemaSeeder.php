<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * configuraciones_sistema (clave primaria = clave, sin id).
 *
 * Las seis primeras claves están respaldadas por el PUDS-UML (RN15-RN21,
 * RF08 y fn_config_int). Las cuatro restantes se marcan «[PROPUESTA]» en su
 * descripción porque el documento habla de ellas (bloqueo de sesión, semanas de
 * fichas) sin fijar el valor: requieren confirmación antes de considerarse
 * requisito aprobado.
 *
 * insertOrIgnore: si el administrador ya cambió un valor, la siembra no lo pisa.
 */
class ConfiguracionSistemaSeeder extends Seeder
{
    private const PARAMETROS = [
        ['max_reprogramaciones', '2', 'int', 'Máximo de reprogramaciones de una cita con el mismo médico (RN18, RF08).'],
        ['edad_mayoria', '18', 'int', 'Edad de mayoría: por debajo se exige responsable legal verificado (RN16, RN22).'],
        ['tolerancia_inasistencia_min', '15', 'int', 'Minutos de tolerancia antes de poder registrar una inasistencia (RN21).'],
        ['max_inasistencias_autoreserva', '3', 'int', 'Inasistencias que bloquean la reserva en línea (RN19).'],
        ['meses_inasistencias', '6', 'int', 'Ventana en meses para contar las inasistencias (RN19).'],
        ['horas_minimas_cambio_web', '12', 'int', 'Horas mínimas de anticipación para reprogramar o cancelar desde la web.'],
        ['max_intentos_login', '5', 'int', '[PROPUESTA] Intentos fallidos antes del bloqueo temporal de la cuenta.'],
        ['minutos_bloqueo_login', '15', 'int', '[PROPUESTA] Duración en minutos del bloqueo temporal por intentos fallidos.'],
        ['minutos_inactividad_sesion', '30', 'int', '[PROPUESTA] Minutos de inactividad tras los cuales se cierra la sesión.'],
        ['semanas_generacion_fichas', '4', 'int', '[PROPUESTA] Semanas por delante para las que se generan las fichas de un horario.'],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::PARAMETROS as [$clave, $valor, $tipo, $descripcion]) {
                DB::table('configuraciones_sistema')->insertOrIgnore([
                    'clave' => $clave,
                    'valor' => $valor,
                    'tipo_dato' => $tipo,
                    'descripcion' => $descripcion,
                ]);
            }
        });
    }
}
