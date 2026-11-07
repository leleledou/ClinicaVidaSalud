<?php

namespace Database\Seeders;

use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Catálogo de laboratorio (perfil mínimo): 8 tipos de examen, sus parámetros y
 * los rangos de referencia por edad en MESES y por sexo (sexo NULL = ambos).
 *
 * Tablas: tipos_examen, parametros_laboratorio, rangos_referencia.
 *
 * Bandas de edad usadas (meses, ambos extremos incluidos):
 *   lactante 0–5 · niño 6–143 (6 m a 11 a) · adolescente 144–215 (12 a 17 a)
 *   adulto 216–719 (18 a 59 a) · adulto mayor 720–1499 (60 a 124 a)
 *
 * Reglas que respeta (las impone también la base):
 *  - Un parámetro no repite su orden dentro del examen (ux_parametros_examen_orden).
 *  - Los rangos de un mismo parámetro y sexo no se solapan en edad ni en vigencia
 *    (ex_rangos_referencia_solape). Además, este seeder nunca mezcla en la misma
 *    banda de edad un rango «ambos sexos» con otro por sexo: así cada paciente
 *    tiene como máximo UN rango aplicable (RegistroClinico::rango lo comprueba).
 *  - critico_min ≤ valor_min ≤ valor_max ≤ critico_max (ck_rangos_valores).
 *  - La glucosa del adulto tiene una versión anterior cerrada (2023–2024) para
 *    mostrar que el rango se elige por la vigencia en la fecha del resultado.
 *
 * Valores de referencia habituales en la literatura clínica, redondeados; son
 * datos de demostración, no una guía médica. Idempotente: busca cada fila por
 * su clave natural y solo inserta lo que falta.
 */
class LaboratorioCatalogoSeeder extends Seeder
{
    public const VIGENCIA = '2025-01-01';

    private const LACTANTE = [0, 5];
    private const NINO = [6, 143];
    private const ADOLESCENTE = [144, 215];
    private const ADULTO = [216, 719];
    private const MAYOR = [720, 1499];
    private const MENOR = [0, 215];          // lactante + niño + adolescente
    private const DESDE_ADOLESCENTE = [144, 1499];
    private const ADULTO_Y_MAYOR = [216, 1499];
    private const TODAS = [0, 1499];

    /**
     * código => [nombre, tipo de muestra, indicaciones previas, parámetros]
     * parámetro: clave => [orden, nombre, unidad, tipo_valor, rangos]
     * rango: [[edad min, edad max], sexo, min, max, crítico min, crítico max, vigente desde, vigente hasta]
     * (las dos últimas son opcionales: por defecto VIGENCIA y sin cierre).
     *
     * La clave del parámetro (HB, GLU…) solo la usan los seeders para referirse a él.
     */
    public const EXAMENES = [
        'HEMOGRAMA' => ['Hemograma', 'Sangre venosa con EDTA', 'No requiere ayuno.', [
            'HB' => [1, 'Hemoglobina', 'g/dL', 'NUMERICO', [
                [self::LACTANTE, null, 9.5, 14.0, 7.0, 20.0],
                [self::NINO, null, 11.5, 15.5, 7.0, 20.0],
                [self::ADOLESCENTE, null, 12.0, 16.0, 7.0, 20.0],
                [self::ADULTO, 'M', 13.5, 17.5, 7.0, 20.0],
                [self::ADULTO, 'F', 12.0, 15.5, 7.0, 20.0],
                [self::MAYOR, null, 12.0, 16.5, 7.0, 20.0],
            ]],
            'HTO' => [2, 'Hematocrito', '%', 'NUMERICO', [
                [self::LACTANTE, null, 28.0, 42.0, 20.0, 60.0],
                [self::NINO, null, 35.0, 45.0, 20.0, 60.0],
                [self::ADOLESCENTE, null, 36.0, 48.0, 20.0, 60.0],
                [self::ADULTO, 'M', 40.0, 52.0, 20.0, 60.0],
                [self::ADULTO, 'F', 36.0, 46.0, 20.0, 60.0],
                [self::MAYOR, null, 36.0, 50.0, 20.0, 60.0],
            ]],
            'LEU' => [3, 'Leucocitos', 'x10³/µL', 'NUMERICO', [
                [self::LACTANTE, null, 6.0, 17.5, 2.0, 30.0],
                [self::NINO, null, 5.0, 14.5, 2.0, 30.0],
                [self::DESDE_ADOLESCENTE, null, 4.5, 11.0, 2.0, 30.0],
            ]],
            'PLQ' => [4, 'Plaquetas', 'x10³/µL', 'NUMERICO', [
                [self::TODAS, null, 150, 450, 50, 1000],
            ]],
        ]],
        'GLUCOSA' => ['Glucosa en ayunas', 'Suero', 'Ayuno de 8 a 12 horas.', [
            'GLU' => [1, 'Glucosa en ayunas', 'mg/dL', 'NUMERICO', [
                [self::MENOR, null, 60, 100, 40, 400],
                // Versión anterior (criterio previo de la clínica), cerrada: no se usa con resultados actuales.
                [self::ADULTO_Y_MAYOR, null, 70, 110, 40, 450, '2023-01-01', '2024-12-31'],
                [self::ADULTO_Y_MAYOR, null, 70, 100, 40, 450],
            ]],
        ]],
        'PERFIL_LIPIDICO' => ['Perfil lipídico', 'Suero', 'Ayuno de 12 horas; sin alcohol las 24 horas previas.', [
            'COL' => [1, 'Colesterol total', 'mg/dL', 'NUMERICO', [
                [self::MENOR, null, null, 170, null, null],
                [self::ADULTO_Y_MAYOR, null, null, 200, null, null],
            ]],
            'HDL' => [2, 'Colesterol HDL', 'mg/dL', 'NUMERICO', [
                [self::MENOR, null, 45, null, null, null],
                [self::ADULTO_Y_MAYOR, 'M', 40, null, null, null],
                [self::ADULTO_Y_MAYOR, 'F', 50, null, null, null],
            ]],
            'LDL' => [3, 'Colesterol LDL', 'mg/dL', 'NUMERICO', [
                [self::MENOR, null, null, 110, null, null],
                [self::ADULTO_Y_MAYOR, null, null, 130, null, null],
            ]],
            'TG' => [4, 'Triglicéridos', 'mg/dL', 'NUMERICO', [
                [self::MENOR, null, null, 100, null, 1000],
                [self::ADULTO_Y_MAYOR, null, null, 150, null, 1000],
            ]],
        ]],
        'EGO' => ['Examen general de orina', 'Orina (primera de la mañana)', 'Higiene previa y chorro medio.', [
            'EGO_COLOR' => [1, 'Color', null, 'TEXTO', []],
            'EGO_ASPECTO' => [2, 'Aspecto', null, 'TEXTO', []],
            'EGO_DENSIDAD' => [3, 'Densidad', null, 'NUMERICO', [
                [self::TODAS, null, 1.005, 1.030, null, null],
            ]],
            'EGO_PH' => [4, 'pH', null, 'NUMERICO', [
                [self::TODAS, null, 5.0, 8.0, null, null],
            ]],
            'EGO_NITRITOS' => [5, 'Nitritos', null, 'TEXTO', []],
            'EGO_LEU' => [6, 'Leucocitos en sedimento', 'por campo', 'NUMERICO', [
                [self::TODAS, null, 0, 5, null, null],
            ]],
        ]],
        'CREATININA' => ['Creatinina sérica', 'Suero', 'No requiere ayuno.', [
            'CREA' => [1, 'Creatinina', 'mg/dL', 'NUMERICO', [
                [[0, 143], null, 0.2, 0.7, null, 3.0],
                [self::ADOLESCENTE, null, 0.5, 1.0, null, 3.0],
                [self::ADULTO, 'M', 0.7, 1.3, null, 4.0],
                [self::ADULTO, 'F', 0.6, 1.1, null, 4.0],
                [self::MAYOR, 'M', 0.7, 1.4, null, 4.0],
                [self::MAYOR, 'F', 0.6, 1.2, null, 4.0],
            ]],
        ]],
        'TSH' => ['Hormona estimulante de la tiroides (TSH)', 'Suero', 'Preferentemente en la mañana.', [
            'TSH' => [1, 'TSH', 'µUI/mL', 'NUMERICO', [
                [[0, 143], null, 0.7, 6.0, 0.01, 50.0],
                [[144, 719], null, 0.4, 4.5, 0.01, 50.0],
                [self::MAYOR, null, 0.4, 5.8, 0.01, 50.0],
            ]],
        ]],
        'HBA1C' => ['Hemoglobina glicosilada (HbA1c)', 'Sangre venosa con EDTA', 'No requiere ayuno.', [
            'HBA1C' => [1, 'Hemoglobina glicosilada', '%', 'NUMERICO', [
                [self::TODAS, null, 4.0, 5.6, null, null],
            ]],
        ]],
        'BHCG' => ['Prueba de embarazo (β-hCG cualitativa)', 'Suero', 'Indicar la fecha de la última menstruación.', [
            'BHCG' => [1, 'β-hCG cualitativa', null, 'TEXTO', []],
        ]],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::EXAMENES as $codigo => [$nombre, $muestra, $indicaciones, $parametros]) {
                $examenId = Siembra::asegurar('tipos_examen', ['codigo' => $codigo], [
                    'nombre' => $nombre,
                    'tipo_muestra' => $muestra,
                    'indicaciones_previas' => $indicaciones,
                    'estado' => 'ACTIVO',
                ]);

                foreach ($parametros as $clave => [$orden, $nombreParam, $unidad, $tipoValor, $rangos]) {
                    $parametroId = Siembra::asegurar('parametros_laboratorio',
                        ['tipo_examen_id' => $examenId, 'orden' => $orden],
                        ['nombre' => $nombreParam, 'unidad' => $unidad, 'tipo_valor' => $tipoValor]);

                    if ($tipoValor === 'TEXTO' && $rangos !== []) {
                        throw new RuntimeException("El parámetro de texto {$clave} no lleva rangos de referencia.");
                    }

                    foreach ($rangos as $r) {
                        [[$edadMin, $edadMax], $sexo, $min, $max, $critMin, $critMax] = $r;
                        Siembra::asegurar('rangos_referencia', [
                            'parametro_laboratorio_id' => $parametroId,
                            'edad_min_meses' => $edadMin,
                            'edad_max_meses' => $edadMax,
                            'sexo' => $sexo,
                            'vigente_desde' => $r[6] ?? self::VIGENCIA,
                        ], [
                            'valor_min' => $min,
                            'valor_max' => $max,
                            'critico_min' => $critMin,
                            'critico_max' => $critMax,
                            'vigente_hasta' => $r[7] ?? null,
                        ]);
                    }
                }
            }
        });
    }
}
