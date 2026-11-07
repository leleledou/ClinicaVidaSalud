<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Database\Seeders\Support\AgendaFichas;
use Database\Seeders\Support\CatalogoClinico;
use Database\Seeders\Support\RegistroCitas;
use Database\Seeders\Support\RegistroClinico;
use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Escenario clínico de demostración (perfil demo): reducido pero completo.
 *
 * Tablas: antecedentes, alergias, consultas, ordenes_laboratorio,
 * resultados_laboratorio, tratamientos, prescripciones; además citas e
 * historiales_cita (ocho citas ATENDIDAS nuevas, CM-000032 a CM-000039) y las
 * fichas de las semanas -8 a -5 que esas citas necesitan.
 *
 * Casos que deja listos para el Ciclo 2:
 *  - CASO DEL NIÑO (pedido por la docente). Pedro (PAC-000001, 8 años, con
 *    responsable legal verificado) consulta en Pediatría por palidez: el
 *    hemograma da hemoglobina BAJA. En la consulta siguiente se indica sulfato
 *    ferroso (tratamientos.resultado_laboratorio_id = ese resultado). En dos
 *    controles, cada uno con su consulta y su orden, la hemoglobina sube hasta
 *    el rango NORMAL y el tratamiento termina FINALIZADO con respuesta MEJORO.
 *      CM-000032 (sem. -8)  Hb  9,6  BAJO   → CM-000033 (sem. -7) inicia el hierro
 *      CM-000034 (sem. -5)  Hb 10,6  BAJO   → CM-000001 (sem. -3) Hb 11,9 NORMAL
 *  - CASO DEL ADULTO. Andrés (PAC-000006, 45 años) en Medicina General: glucosa
 *    en ayunas 182 mg/dL y HbA1c 7,9 % → metformina; controles 131 y 96 mg/dL.
 *    El tratamiento sigue EN_CURSO (es crónico) con respuesta MEJORO.
 *  - VALORES CRÍTICOS, sembrados a propósito en pacientes, edades y
 *    especialidades distintas; cada uno dispara el aviso VALOR_CRITICO al médico:
 *      Fernanda (PAC-000008, 68 años, Geriatría)      hemoglobina 6,6 g/dL  CRITICO_BAJO
 *      Carlos   (PAC-000004, 33 años, Med. General)   glucosa 486 mg/dL     CRITICO_ALTO
 *      Ricardo  (PAC-000011, 52 años, Cardiología)    creatinina 4,6 mg/dL  CRITICO_ALTO
 *  - ADVERTENCIA DE ALERGIAS. Luciana (PAC-000005) es alérgica a la penicilina:
 *    su amigdalitis se trata con azitromicina, sin ninguna penicilina. Mario
 *    (PAC-000009) es alérgico al ibuprofeno: recibe paracetamol y se omite el
 *    diclofenaco tópico de la pauta habitual.
 *
 * Cada una de las 19 citas ATENDIDAS de la demo tiene su consulta FINALIZADA,
 * insertada ya completa (tg_consultas_finalizada_inmutable impide editarla).
 * Las reglas se validan en RegistroClinico antes de escribir; si el escenario
 * no se cumple (por ejemplo, la hemoglobina del control no da NORMAL) la
 * siembra se detiene con SiembraInvalida.
 *
 * Idempotente: citas por código, consultas por cita, órdenes por código,
 * tratamientos por (consulta, descripción), antecedentes y alergias por su
 * texto. Requiere Ciclo1DemoSeeder y LaboratorioCatalogoSeeder.
 */
class ClinicaDemoSeeder extends Seeder
{
    private const SEMILLA = 20261107;

    /**
     * Pacientes de los casos guiados (niño, adulto y valores críticos). Su evolución la fija este
     * seeder: la población extendida no les agrega consultas al azar que la contradigan.
     */
    public const PACIENTES_CASO = ['PAC-000001', 'PAC-000004', 'PAC-000006', 'PAC-000008', 'PAC-000011'];

    /** Citas ATENDIDAS nuevas para los casos clínicos: código, paciente, médico, especialidad, [semana, día, hora], creador, motivo. */
    private const CITAS = [
        ['CM-000032', 'PAC-000001', 'MED-004', 'Pediatría', [-8, 1, '09:00'], 'rmendoza', 'Palidez y cansancio'],
        ['CM-000033', 'PAC-000001', 'MED-004', 'Pediatría', [-7, 1, '09:00'], 'rmendoza', 'Revisión de resultados de laboratorio'],
        ['CM-000034', 'PAC-000001', 'MED-004', 'Pediatría', [-5, 1, '09:00'], 'rmendoza', 'Control de anemia'],
        ['CM-000035', 'PAC-000006', 'MED-002', 'Medicina General', [-8, 1, '14:00'], 'ebarrios', 'Sed excesiva y orina frecuente'],
        ['CM-000036', 'PAC-000006', 'MED-002', 'Medicina General', [-7, 1, '14:00'], 'ebarrios', 'Revisión de resultados de laboratorio'],
        ['CM-000037', 'PAC-000006', 'MED-002', 'Medicina General', [-2, 1, '14:40'], 'ebarrios', 'Control de glucosa'],
        ['CM-000038', 'PAC-000004', 'MED-001', 'Medicina General', [-5, 3, '08:40'], 'rmendoza', 'Sed intensa, vómitos y decaimiento'],
        ['CM-000039', 'PAC-000011', 'MED-009', 'Cardiología', [-4, 3, '09:00'], 'sortuno', 'Control cardiológico: edemas y cansancio'],
    ];

    /** paciente, tipo, descripción, fecha de inicio (literal, «cita:CODIGO» o null), vigente */
    private const ANTECEDENTES = [
        ['PAC-000001', 'FAMILIAR', 'Madre con anemia ferropénica durante el embarazo', null, true],
        ['PAC-000004', 'HABITO', 'Tabaquismo: 5 cigarrillos al día', '2015-01-01', true],
        ['PAC-000004', 'FAMILIAR', 'Madre con diabetes mellitus tipo 2', null, true],
        ['PAC-000004', 'CRONICO', 'Diabetes mellitus tipo 2 (debut con hiperglucemia grave)', 'cita:CM-000038', true],
        ['PAC-000005', 'QUIRURGICO', 'Apendicectomía', '2015-06-10', true],
        ['PAC-000006', 'FAMILIAR', 'Padre con diabetes mellitus tipo 2', null, true],
        ['PAC-000006', 'CRONICO', 'Diabetes mellitus tipo 2', 'cita:CM-000036', true],
        ['PAC-000006', 'MEDICACION_ACTUAL', 'Metformina 850 mg cada 12 horas', 'cita:CM-000036', true],
        ['PAC-000007', 'GINECO_OBSTETRICO', 'G0 P0; menarquia a los 12 años; ciclos regulares', null, true],
        ['PAC-000008', 'CRONICO', 'Hipertensión arterial', '2012-03-01', true],
        ['PAC-000008', 'MEDICACION_ACTUAL', 'Losartán 50 mg al día y ácido acetilsalicílico 100 mg al día', '2018-05-01', true],
        ['PAC-000008', 'GINECO_OBSTETRICO', 'G3 P3; menopausia a los 50 años', null, true],
        ['PAC-000009', 'CRONICO', 'Artrosis de rodillas', '2019-01-01', true],
        ['PAC-000009', 'HABITO', 'Exfumador (20 paquetes-año); dejó de fumar en 2010', '1975-01-01', false],
        ['PAC-000010', 'FAMILIAR', 'Padre con infarto agudo de miocardio a los 60 años', null, true],
        ['PAC-000011', 'CRONICO', 'Hipertensión arterial', '2016-08-01', true],
        ['PAC-000011', 'PATOLOGICO', 'Fibrilación auricular paroxística', '2023-02-15', true],
        ['PAC-000011', 'CRONICO', 'Enfermedad renal hipertensiva', 'cita:CM-000039', true],
        ['PAC-000015', 'QUIRURGICO', 'Colecistectomía laparoscópica', '2018-09-20', true],
        ['PAC-000015', 'PATOLOGICO', 'Neumonía adquirida en la comunidad, resuelta', '2020-07-01', false],
        ['PAC-000016', 'GINECO_OBSTETRICO', 'G1 P1 (parto vaginal en 2019)', null, true],
    ];

    /** paciente, tipo, sustancia, reacción, severidad */
    private const ALERGIAS = [
        ['PAC-000002', 'ALIMENTO', 'Maní', 'Urticaria y edema de labios', 'MODERADA'],
        ['PAC-000005', 'MEDICAMENTO', 'Penicilina', 'Anafilaxia en 2019', 'GRAVE'],
        ['PAC-000009', 'MEDICAMENTO', 'Ibuprofeno', 'Broncoespasmo', 'MODERADA'],
        ['PAC-000011', 'MEDICAMENTO', 'Sulfametoxazol', 'Erupción cutánea', 'LEVE'],
        ['PAC-000012', 'AMBIENTAL', 'Polen', 'Rinitis estacional', 'LEVE'],
        ['PAC-000017', 'ALIMENTO', 'Huevo', 'Urticaria', 'MODERADA'],
    ];

    /** @var array<string,array<string,int>> código de orden => clave de parámetro => id del resultado */
    private array $resultados = [];

    public function run(): void
    {
        Siembra::exigirNoProduccion(static::class);
        if (Siembra::id('citas', ['codigo' => 'CM-000001']) === null) {
            throw new RuntimeException('Ejecute primero Ciclo1DemoSeeder: el escenario clínico usa sus pacientes y citas.');
        }
        if (Siembra::id('tipos_examen', ['codigo' => 'HEMOGRAMA']) === null) {
            throw new RuntimeException('Falta el catálogo de laboratorio: ejecute CatalogosSeeder.');
        }

        DB::transaction(function () {
            AgendaFichas::generar(-8, -5);
            $this->citas();
            $this->historias();
            foreach ($this->atenciones() as $codigo => $atencion) {
                $this->atender($codigo, $atencion);
            }
        });
    }

    // ------------------------------------------------------------------ citas

    private function citas(): void
    {
        foreach (self::CITAS as [$codigo, $paciente, $medico, $esp, $ficha, $creador, $motivo]) {
            RegistroCitas::registrar([
                'codigo' => $codigo,
                'paciente_id' => $this->paciente($paciente),
                'usuario_id' => $this->usuario($creador),
                'ficha_id' => $this->ficha($medico, $esp, ...$ficha),
                'estado' => 'ATENDIDA',
                'origen' => 'RECEPCION',
                'canal' => 'WEB',
                'motivo' => $motivo,
                'usuario_atiende_id' => $this->usuarioMedico($medico),
                'confirmar' => true,
            ]);
        }
    }

    // ------------------------------------------------------- historia clínica

    private function historias(): void
    {
        foreach (self::ANTECEDENTES as [$paciente, $tipo, $descripcion, $inicio, $vigente]) {
            [$historia, $sexo, $edad] = $this->historia($paciente);
            if ($inicio !== null && str_starts_with($inicio, 'cita:')) {
                $inicio = $this->cita(substr($inicio, 5))->fecha;
            }
            RegistroClinico::antecedente($historia, $sexo, $edad, $tipo, $descripcion, $inicio, $vigente);
        }
        foreach (self::ALERGIAS as [$paciente, $tipo, $sustancia, $reaccion, $severidad]) {
            RegistroClinico::alergia($this->historia($paciente)[0], $tipo, $sustancia, $reaccion, $severidad);
        }
    }

    /** @return array{int,string,int} id de la historia, sexo y edad actual */
    private function historia(string $paciente): array
    {
        $h = DB::table('historias_clinicas as h')->join('pacientes as p', 'p.id', '=', 'h.paciente_id')
            ->join('personas as pe', 'pe.id', '=', 'p.persona_id')->where('p.codigo', $paciente)
            ->first(['h.id', 'pe.sexo', 'pe.fecha_nacimiento'])
            ?? throw new RuntimeException("Falta la historia clínica de {$paciente}: ejecute PacienteDemoSeeder.");

        return [(int) $h->id, $h->sexo, (int) date_diff(date_create($h->fecha_nacimiento), date_create(Siembra::ahora()->toDateString()))->y];
    }

    // --------------------------------------------------------------- atención

    /**
     * Atención de cada cita ATENDIDA de la demo, en orden cronológico dentro de
     * cada caso (un tratamiento cita resultados de órdenes anteriores).
     *
     * consulta: [cie10, diagnóstico, anamnesis, examen físico, indicaciones, perfil de signos, signos fijos]
     * órdenes: [examen, prioridad, estado, toma, resultado, revisión, valores, interpretaciones esperadas]
     *   (cada momento: minutos después del fin de la consulta o [días después, 'HH:MM'])
     * tratamientos: callable que recibe la cita y devuelve los datos del tratamiento
     *
     * @return array<string,array<string,mixed>>
     */
    private function atenciones(): array
    {
        $hb = fn (float $hb, float $hto, float $leu, int $plq) => ['HB' => $hb, 'HTO' => $hto, 'LEU' => $leu, 'PLQ' => $plq];

        return [
            // ------------------------------------------------ caso del niño (Pedro)
            'CM-000032' => [
                'consulta' => ['D64.9', 'Anemia, no especificada (sospecha de ferropenia)',
                    'La madre refiere palidez, cansancio y poco apetito desde hace un mes. Dieta pobre en carnes rojas.',
                    'Palidez de piel y mucosas. Soplo sistólico funcional grado I/VI. Sin hepatoesplenomegalia.',
                    'Se solicita hemograma. Dieta rica en hierro mientras tanto. Volver con el resultado.',
                    'normal', ['peso_kg' => 24.5, 'talla_cm' => 126.0, 'frecuencia_cardiaca' => 102]],
                'ordenes' => [['HEMOGRAMA', 'NORMAL', 'REVISADA', 25, 150, 300, $hb(9.6, 30.5, 7.8, 385),
                    ['HB' => 'BAJO', 'HTO' => 'BAJO', 'LEU' => 'NORMAL', 'PLQ' => 'NORMAL']]],
            ],
            'CM-000033' => [
                'consulta' => ['D50.9', 'Anemia por deficiencia de hierro, sin otra especificación',
                    'Acude con el hemograma: hemoglobina 9,6 g/dL y hematocrito 30,5 %. Persiste la palidez.',
                    'Palidez leve. Resto del examen sin cambios.',
                    'Sulfato ferroso en ayunas con jugo de naranja, lejos de la leche. Control en 2 semanas con hemograma.',
                    'normal', ['peso_kg' => 24.6, 'talla_cm' => 126.2, 'frecuencia_cardiaca' => 98]],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Ferroterapia oral por anemia ferropénica',
                    'resultado' => ['LAB-000032-1', 'HB'],
                    'fin' => 'CM-000001',
                    'estado' => 'FINALIZADO',
                    'respuesta' => 'MEJORO',
                    'observacion' => 'Hemoglobina 9,6 g/dL (BAJO) → 10,6 g/dL (BAJO) → 11,9 g/dL (NORMAL). Se completa el esquema y se mantiene la dieta rica en hierro.',
                    'prescripciones' => [['Sulfato ferroso', 'Gotas 125 mg/mL: 3 mg/kg/día de hierro elemental (3 mL)', 'Cada 24 horas, en ayunas con jugo de naranja', 'hasta:CM-000001', 'Oral']],
                ]],
            ],
            'CM-000034' => [
                'consulta' => ['D50.9', 'Anemia por deficiencia de hierro, en tratamiento',
                    'Primer control con sulfato ferroso; buena tolerancia, heces oscuras. Mejor apetito.',
                    'Mucosas mejor coloreadas. Sin soplo.',
                    'Continuar el sulfato ferroso. Hemograma de control.',
                    'normal', ['peso_kg' => 25.0, 'talla_cm' => 126.6, 'frecuencia_cardiaca' => 94]],
                'ordenes' => [['HEMOGRAMA', 'NORMAL', 'REVISADA', 25, 150, 300, $hb(10.6, 33.2, 8.1, 360),
                    ['HB' => 'BAJO', 'HTO' => 'BAJO']]],
            ],
            'CM-000001' => [
                'consulta' => ['Z09.8', 'Control de anemia ferropénica tratada: hemoglobina normalizada',
                    'Segundo control. Asintomático, activo, buen rendimiento escolar.',
                    'Buen color de piel y mucosas. Examen físico normal.',
                    'Hemoglobina en rango normal: se termina la ferroterapia. Control de niño sano en 6 meses.',
                    'normal', ['peso_kg' => 25.4, 'talla_cm' => 127.0, 'frecuencia_cardiaca' => 90]],
                'ordenes' => [['HEMOGRAMA', 'NORMAL', 'REVISADA', 20, 140, 240, $hb(11.9, 36.4, 7.6, 340),
                    ['HB' => 'NORMAL', 'HTO' => 'NORMAL']]],
            ],

            // ---------------------------------------------- caso del adulto (Andrés)
            'CM-000035' => [
                'consulta' => ['R73.9', 'Hiperglucemia, no especificada',
                    'Sed excesiva, orina frecuente y cansancio de un mes. Padre diabético.',
                    'IMC 29. Pies sin lesiones. Resto sin alteraciones.',
                    'Glucosa en ayunas y hemoglobina glicosilada mañana a primera hora. Reducir azúcares y bebidas gaseosas.',
                    'normal', ['peso_kg' => 86.0, 'talla_cm' => 171.0, 'presion_sistolica' => 128, 'presion_diastolica' => 82]],
                'ordenes' => [
                    ['GLUCOSA', 'NORMAL', 'REVISADA', [1, '07:30'], [1, '10:30'], [1, '16:00'], ['GLU' => 182], ['GLU' => 'ALTO']],
                    ['HBA1C', 'NORMAL', 'REVISADA', [1, '07:30'], [1, '11:00'], [1, '16:00'], ['HBA1C' => 7.9], ['HBA1C' => 'ALTO']],
                ],
            ],
            'CM-000036' => [
                'consulta' => ['E11.9', 'Diabetes mellitus tipo 2 sin complicaciones',
                    'Trae resultados: glucosa en ayunas 182 mg/dL y HbA1c 7,9 %. Se confirma diabetes tipo 2.',
                    'IMC 29. Sensibilidad de pies conservada. Fondo de ojo sin retinopatía.',
                    'Metformina con las comidas, plan alimentario y caminata diaria. Perfil lipídico y creatinina de base.',
                    'normal', ['peso_kg' => 85.6, 'talla_cm' => 171.0, 'presion_sistolica' => 126, 'presion_diastolica' => 80]],
                'ordenes' => [
                    ['PERFIL_LIPIDICO', 'NORMAL', 'REVISADA', [1, '07:30'], [1, '11:30'], [2, '09:00'],
                        ['COL' => 214, 'HDL' => 41, 'LDL' => 138, 'TG' => 175], ['COL' => 'ALTO', 'LDL' => 'ALTO', 'TG' => 'ALTO']],
                    ['CREATININA', 'NORMAL', 'REVISADA', [1, '07:30'], [1, '11:30'], [2, '09:00'], ['CREA' => 0.92], ['CREA' => 'NORMAL']],
                ],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Metformina y plan alimentario por diabetes mellitus tipo 2',
                    'resultado' => ['LAB-000035-1', 'GLU'],
                    'fin' => null,
                    'estado' => 'EN_CURSO',
                    'respuesta' => 'MEJORO',
                    'observacion' => 'Glucosa en ayunas 182 → 131 → 96 mg/dL (NORMAL); HbA1c inicial 7,9 %. Tratamiento crónico: continúa.',
                    'prescripciones' => [['Metformina', '850 mg', 'Cada 12 horas con las comidas', 90, 'Oral']],
                ]],
            ],
            'CM-000012' => [
                'consulta' => ['E11.9', 'Diabetes mellitus tipo 2 sin complicaciones, en control',
                    'Control con metformina; buena tolerancia. Glucometrías de 120 a 140 mg/dL.',
                    'Bajó 2 kg. Pies sin lesiones.',
                    'Continuar la metformina. Glucosa en ayunas de control.',
                    'normal', ['peso_kg' => 83.8, 'talla_cm' => 171.0, 'presion_sistolica' => 124, 'presion_diastolica' => 78]],
                'ordenes' => [['GLUCOSA', 'NORMAL', 'REVISADA', [1, '07:30'], [1, '10:30'], [2, '09:00'], ['GLU' => 131], ['GLU' => 'ALTO']]],
            ],
            'CM-000037' => [
                'consulta' => ['E11.9', 'Diabetes mellitus tipo 2 sin complicaciones, controlada',
                    'Control: buena adherencia al plan alimentario; camina 30 minutos al día.',
                    'Bajó 4 kg desde el diagnóstico. Examen sin alteraciones.',
                    'Mantener la metformina y el plan alimentario. HbA1c en 3 meses.',
                    'normal', ['peso_kg' => 82.1, 'talla_cm' => 171.0, 'presion_sistolica' => 122, 'presion_diastolica' => 78]],
                'ordenes' => [['GLUCOSA', 'NORMAL', 'REVISADA', [1, '07:30'], [1, '10:30'], [1, '17:00'], ['GLU' => 96], ['GLU' => 'NORMAL']]],
            ],

            // ------------------------------------------------- valor crítico (Fernanda)
            'CM-000021' => [
                'consulta' => ['D64.9', 'Anemia, no especificada (descartar sangrado digestivo)',
                    'Cansancio extremo y mareos de dos semanas; heces oscuras. Toma ácido acetilsalicílico 100 mg al día.',
                    'Palidez marcada, taquicardia de 108 lpm, presión 104/62 mmHg. Abdomen blando, no doloroso.',
                    'Hemograma URGENTE. Ante el resultado crítico se deriva a emergencias del hospital de referencia.',
                    'normal', ['presion_sistolica' => 104, 'presion_diastolica' => 62, 'frecuencia_cardiaca' => 108, 'saturacion_o2' => 91]],
                'ordenes' => [['HEMOGRAMA', 'URGENTE', 'REVISADA', 10, 80, 95, $hb(6.6, 21.0, 7.2, 310),
                    ['HB' => 'CRITICO_BAJO', 'HTO' => 'BAJO']]],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Derivación a emergencias para transfusión y estudio de sangrado digestivo',
                    'resultado' => ['LAB-000021-1', 'HB'],
                    'fin' => null,
                    'estado' => 'EN_CURSO',
                    'respuesta' => 'PENDIENTE',
                    'observacion' => 'Hemoglobina 6,6 g/dL (crítico). Se suspende el ácido acetilsalicílico; derivada con referencia escrita.',
                    'prescripciones' => [['Omeprazol', '40 mg', 'Cada 24 horas, en ayunas', 30, 'Oral']],
                ]],
            ],

            // ------------------------- valor crítico: glucosa en Medicina General (Carlos, 33 años en esa fecha)
            'CM-000038' => [
                'consulta' => ['R73.9', 'Hiperglucemia, no especificada (glucemia crítica; descartar cetoacidosis)',
                    'Sed intensa, orina abundante, vómitos y decaimiento de 3 días. Madre diabética. Sin diagnóstico previo.',
                    'Deshidratado, mucosas secas, taquicárdico. Aliento sin olor cetónico evidente. Abdomen blando.',
                    'Glucosa URGENTE. Ante el valor crítico se deriva a emergencias para hidratación intravenosa e insulina.',
                    'normal', ['presion_sistolica' => 104, 'presion_diastolica' => 66, 'frecuencia_cardiaca' => 112, 'temperatura_c' => 36.8, 'saturacion_o2' => 93]],
                'ordenes' => [
                    ['GLUCOSA', 'URGENTE', 'REVISADA', 8, 50, 60, ['GLU' => 486], ['GLU' => 'CRITICO_ALTO']],
                    ['HBA1C', 'NORMAL', 'REVISADA', 8, [2, '11:00'], [2, '15:00'], ['HBA1C' => 11.8], ['HBA1C' => 'ALTO']],
                ],
                'observaciones' => ['GLU' => 'Muestra sin ayuno (urgente).'],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Derivación a emergencias por hiperglucemia crítica',
                    'resultado' => ['LAB-000038-1', 'GLU'],
                    'fin' => 'dias:3', 'estado' => 'FINALIZADO', 'respuesta' => 'MEJORO',
                    'observacion' => 'Glucosa 486 mg/dL (crítico). Hospitalizado 48 horas con hidratación e insulina; alta con glucosa de 168 mg/dL y control en Medicina General.',
                    'prescripciones' => [],
                ]],
            ],

            // ------------------------- valor crítico: creatinina en Cardiología (Ricardo, 52 años)
            'CM-000039' => [
                'consulta' => ['I12.0', 'Enfermedad renal hipertensiva con insuficiencia renal',
                    'Hipertenso con mal control; edemas en las piernas, cansancio y orina espumosa de 2 semanas. Alérgico al sulfametoxazol.',
                    'Edemas con fóvea en ambos tobillos. Ruidos cardíacos arrítmicos ocasionales, sin soplos.',
                    'Creatinina y hemograma URGENTES. Se suspende el losartán y se deriva a nefrología.',
                    'hta', ['peso_kg' => 88.4, 'talla_cm' => 174.0, 'presion_sistolica' => 168, 'presion_diastolica' => 102]],
                'ordenes' => [
                    ['CREATININA', 'URGENTE', 'REVISADA', 10, 70, 85, ['CREA' => 4.6], ['CREA' => 'CRITICO_ALTO']],
                    ['HEMOGRAMA', 'URGENTE', 'REVISADA', 10, 70, 85, $hb(11.2, 34.0, 6.9, 240), ['HB' => 'BAJO', 'HTO' => 'BAJO']],
                ],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Derivación urgente a nefrología y ajuste del antihipertensivo',
                    'resultado' => ['LAB-000039-1', 'CREA'],
                    'fin' => null, 'estado' => 'EN_CURSO', 'respuesta' => 'PENDIENTE',
                    'observacion' => 'Creatinina 4,6 mg/dL (crítico). Se suspende el losartán (nefrotóxico en esta situación); en seguimiento por nefrología.',
                    'prescripciones' => [['Amlodipino', '10 mg', 'Cada 24 horas', 30, 'Oral']],
                ]],
            ],

            // ---------------------------------------- alergias (Luciana y Mario)
            'CM-000011' => [
                'consulta' => ['J03.9', 'Amigdalitis aguda, no especificada',
                    'Odinofagia intensa y fiebre de 2 días. ALÉRGICA A PENICILINA (anafilaxia).',
                    'Amígdalas con exudado; adenopatías cervicales dolorosas.',
                    'Por la alergia a la penicilina se indica azitromicina (no amoxicilina). Líquidos y reposo.',
                    'fiebre', []],
                'ordenes' => [['HEMOGRAMA', 'NORMAL', 'REVISADA', 20, 160, [1, '09:00'], $hb(13.4, 40.1, 13.2, 290), ['LEU' => 'ALTO']]],
                'tratamientos' => [fn ($c) => [
                    'descripcion' => 'Antibioticoterapia por amigdalitis aguda en paciente alérgica a penicilina',
                    'fin' => 'dias:5', 'estado' => 'FINALIZADO', 'respuesta' => 'MEJORO',
                    'observacion' => 'Afebril al segundo día; completó la azitromicina sin reacciones.',
                    'prescripciones' => [
                        ['Azitromicina', '500 mg', 'Cada 24 horas', 3, 'Oral'],
                        ['Paracetamol', '1 g', 'Cada 8 horas si hay fiebre', 3, 'Oral'],
                    ],
                ]],
            ],
            'CM-000019' => [
                'consulta' => ['M17.9', 'Gonartrosis, no especificada',
                    'Dolor en ambas rodillas al caminar y subir gradas. Alérgico al ibuprofeno (broncoespasmo).',
                    'Crepitación bilateral, sin derrame. Marcha antálgica leve.',
                    'Paracetamol; se evitan los antiinflamatorios por la alergia. Fisioterapia y control de peso.',
                    'normal', []],
                'ordenes' => [['CREATININA', 'NORMAL', 'REVISADA', 15, 200, [1, '10:00'], ['CREA' => 1.55], ['CREA' => 'ALTO']]],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Analgesia por artrosis sin antiinflamatorios (alergia al ibuprofeno)',
                    'fin' => 'dias:12', 'estado' => 'FINALIZADO', 'respuesta' => 'SIN_CAMBIO',
                    'observacion' => 'Dolor parcialmente controlado; se refuerza la fisioterapia. Creatinina levemente elevada en seguimiento.',
                    'prescripciones' => [['Paracetamol', '1 g', 'Cada 8 horas', 12, 'Oral']],
                ]],
            ],

            // ---------------------------------------------------- resto de la demo
            'CM-000016' => [
                'consulta' => ['I10', 'Hipertensión esencial (primaria)',
                    'Chequeo cardiológico. Registros domiciliarios de 145/92 mmHg. Padre con infarto a los 60 años.',
                    'Ruidos cardíacos rítmicos; sin soplos. Pulsos simétricos.',
                    'Losartán y atorvastatina; dieta hiposódica y baja en grasas. Control en un mes.',
                    'hta', []],
                'ordenes' => [
                    ['PERFIL_LIPIDICO', 'NORMAL', 'REVISADA', [1, '07:40'], [1, '12:00'], [2, '10:00'],
                        ['COL' => 228, 'HDL' => 38, 'LDL' => 152, 'TG' => 190], ['COL' => 'ALTO', 'HDL' => 'BAJO', 'LDL' => 'ALTO', 'TG' => 'ALTO']],
                    ['CREATININA', 'NORMAL', 'REVISADA', [1, '07:40'], [1, '12:00'], [2, '10:00'], ['CREA' => 1.02], ['CREA' => 'NORMAL']],
                ],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Antihipertensivo e hipolipemiante',
                    'fin' => null, 'estado' => 'EN_CURSO', 'respuesta' => 'PENDIENTE',
                    'prescripciones' => [
                        ['Losartán', '50 mg', 'Cada 24 horas', 30, 'Oral'],
                        ['Atorvastatina', '20 mg', 'Cada 24 horas, por la noche', 30, 'Oral'],
                    ],
                ]],
            ],
            'CM-000024' => [
                'consulta' => ['Z01.4', 'Examen ginecológico (general) (de rutina)',
                    'Control ginecológico anual; ciclos regulares; desea descartar embarazo antes de un viaje.',
                    'Mamas sin nódulos. Especuloscopía normal.',
                    'Autoexamen mensual de mamas. Control anual.',
                    'normal', []],
                'ordenes' => [
                    ['BHCG', 'NORMAL', 'RESULTADO_LISTO', 15, 120, null, ['BHCG' => 'Negativo'], []],
                    ['EGO', 'NORMAL', 'RESULTADO_LISTO', [1, '07:00'], [1, '11:00'], null,
                        ['EGO_COLOR' => 'Amarillo claro', 'EGO_ASPECTO' => 'Transparente', 'EGO_DENSIDAD' => 1.018, 'EGO_PH' => 6.0,
                            'EGO_NITRITOS' => 'Negativo', 'EGO_LEU' => 2], ['EGO_LEU' => 'NORMAL']],
                ],
            ],
            'CM-000026' => [
                'consulta' => ['K02.1', 'Caries de la dentina',
                    'Sensibilidad al frío en un molar inferior izquierdo desde hace 3 semanas.',
                    'Caries en dentina en la pieza 36, sin compromiso pulpar.',
                    'Se restaura con resina. Cepillado con pasta fluorada e hilo dental.',
                    'normal', []],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Restauración con resina compuesta en la pieza 36',
                    'fin' => 'dias:0', 'estado' => 'FINALIZADO', 'respuesta' => 'MEJORO',
                    'prescripciones' => [],
                ]],
            ],
            'CM-000027' => [
                'consulta' => ['K04.1', 'Necrosis de la pulpa',
                    'Dolor previo en un molar con caries extensa, sin posibilidad de restauración.',
                    'Pieza 46 con destrucción coronaria; sin tumefacción.',
                    'Exodoncia simple. Frío local las primeras horas y dieta blanda.',
                    'normal', ['presion_sistolica' => 126, 'presion_diastolica' => 80]],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Exodoncia simple y cuidados posoperatorios',
                    'fin' => 'dias:5', 'estado' => 'FINALIZADO', 'respuesta' => 'MEJORO',
                    'observacion' => 'Cicatrización normal del alvéolo.',
                    'prescripciones' => [
                        ['Amoxicilina', '500 mg', 'Cada 8 horas', 5, 'Oral'],
                        ['Paracetamol', '1 g', 'Cada 8 horas si hay dolor', 3, 'Oral'],
                    ],
                ]],
            ],
            'CM-000028' => [
                'consulta' => ['Z00.1', 'Control de salud de rutina del niño',
                    'Control de niño sano. Alergia al maní conocida; sin episodios recientes.',
                    'Peso y talla en percentiles 50. Examen físico normal.',
                    'Evitar el maní y sus derivados. Próximo control en 6 meses.',
                    'normal', []],
            ],
            'CM-000030' => [
                'consulta' => ['I10', 'Hipertensión esencial (primaria)',
                    'Control geriátrico de hipertensión; cefalea ocasional.',
                    'Presión elevada; ruidos cardíacos rítmicos; sin edemas.',
                    'Enalapril y dieta hiposódica. Creatinina y glucosa de control.',
                    'hta', []],
                'ordenes' => [
                    ['CREATININA', 'NORMAL', 'REVISADA', 20, 180, [1, '10:00'], ['CREA' => 1.08], ['CREA' => 'NORMAL']],
                    ['GLUCOSA', 'NORMAL', 'REVISADA', [1, '07:30'], [1, '10:30'], [1, '15:00'], ['GLU' => 98], ['GLU' => 'NORMAL']],
                ],
                'tratamientos' => [fn () => [
                    'descripcion' => 'Antihipertensivo de mantenimiento',
                    'fin' => 'dias:10', 'estado' => 'SUSPENDIDO', 'respuesta' => 'SIN_CAMBIO',
                    'observacion' => 'El paciente se dio de baja de la clínica; continúa su control en otro centro.',
                    'prescripciones' => [['Enalapril', '10 mg', 'Cada 12 horas', 30, 'Oral']],
                ]],
            ],
        ];
    }

    /** @param array<string,mixed> $a */
    private function atender(string $codigo, array $a): void
    {
        $cita = RegistroClinico::cita($this->citaId($codigo));
        mt_srand(self::SEMILLA + (int) substr($codigo, 3));

        [$cie, $diagnostico, $anamnesis, $examen, $indicaciones, $perfil, $fijos] = $a['consulta'];
        $inicio = $cita->inicio->addMinutes(2);
        $fin = $cita->fin->subMinutes(3);
        $consultaId = RegistroClinico::consulta($cita, [
            'inicio' => $inicio, 'fin' => $fin,
            'anamnesis' => $anamnesis, 'examen_fisico' => $examen,
            'signos' => array_merge(CatalogoClinico::signos($cita->edad, $cita->sexo, $perfil), $fijos),
            'codigo_cie10' => $cie, 'diagnostico' => $diagnostico, 'indicaciones' => $indicaciones,
        ]) ?? (int) DB::table('consultas')->where('cita_id', $cita->id)->value('id');
        $fin = Siembra::desdeTimestamp((string) DB::table('consultas')->where('id', $consultaId)->value('fin'));

        foreach ($a['ordenes'] ?? [] as $i => [$examenCodigo, $prioridad, $estado, $toma, $resultado, $revision, $valores, $esperado]) {
            $codigoOrden = 'LAB-'.substr($codigo, 3).'-'.($i + 1);
            $this->resultados[$codigoOrden] = RegistroClinico::orden($cita, $consultaId, [
                'codigo' => $codigoOrden,
                'examen' => $examenCodigo,
                'prioridad' => $prioridad,
                'estado' => $estado,
                'solicitud' => $fin->subMinute(),
                'toma' => $this->momento($fin, $toma),
                'resultado' => $this->momento($fin, $resultado),
                'revision' => $this->momento($fin, $revision),
                'laboratorio' => 'Laboratorio clínico Clínica Vida Salud',
                'valores' => $valores,
                'esperado' => $esperado,
                'observaciones' => $a['observaciones'] ?? [],
            ]);
        }

        foreach ($a['tratamientos'] ?? [] as $definir) {
            $t = $definir($cita);
            $finTratamiento = match (true) {
                $t['fin'] === null => null,
                str_starts_with($t['fin'], 'dias:') => CarbonImmutable::parse($cita->fecha)->addDays((int) substr($t['fin'], 5))->toDateString(),
                default => $this->cita($t['fin'])->fecha,
            };
            $resultadoId = null;
            if (isset($t['resultado'])) {
                [$orden, $clave] = $t['resultado'];
                $resultadoId = $this->resultados[$orden][$clave]
                    ?? throw new RuntimeException("El tratamiento de {$codigo} cita el resultado {$clave} de {$orden}, que no existe.");
            }
            // «hasta:CM-xxxxxx»: la prescripción dura hasta la fecha de esa cita de control.
            $prescripciones = array_map(function ($p) use ($cita) {
                if (is_string($p[3]) && str_starts_with($p[3], 'hasta:')) {
                    $p[3] = (int) CarbonImmutable::parse($cita->fecha)->diffInDays(CarbonImmutable::parse($this->cita(substr($p[3], 6))->fecha));
                }

                return $p;
            }, $t['prescripciones']);

            RegistroClinico::tratamiento($cita, $consultaId, [
                'descripcion' => $t['descripcion'],
                'resultado_id' => $resultadoId,
                'fecha_inicio' => $cita->fecha,
                'fecha_fin' => $finTratamiento,
                'estado' => $t['estado'],
                'respuesta' => $t['respuesta'],
                'observacion' => $t['observacion'] ?? null,
                'prescripciones' => $prescripciones,
            ]);
        }
    }

    /** Momento relativo al fin de la consulta: minutos después o [días después, 'HH:MM']. */
    private function momento(CarbonImmutable $fin, int|array|null $spec): ?CarbonImmutable
    {
        return match (true) {
            $spec === null => null,
            is_int($spec) => $fin->addMinutes($spec),
            default => $fin->startOfDay()->addDays($spec[0])->setTimeFromTimeString($spec[1]),
        };
    }

    // -------------------------------------------------------------- búsquedas

    private function citaId(string $codigo): int
    {
        return Siembra::id('citas', ['codigo' => $codigo]) ?? throw new RuntimeException("Falta la cita {$codigo}.");
    }

    private function cita(string $codigo): object
    {
        return DB::table('citas as c')->join('fichas as f', 'f.id', '=', 'c.ficha_id')
            ->where('c.codigo', $codigo)->first(['c.id', 'f.fecha'])
            ?? throw new RuntimeException("Falta la cita {$codigo}.");
    }

    private function paciente(string $codigo): int
    {
        return Siembra::id('pacientes', ['codigo' => $codigo]) ?? throw new RuntimeException("Falta {$codigo}: ejecute PacienteDemoSeeder.");
    }

    private function usuario(string $nombre): int
    {
        return Siembra::id('usuarios', ['nombre_usuario' => $nombre]) ?? throw new RuntimeException("Falta el usuario {$nombre}.");
    }

    private function usuarioMedico(string $codigo): int
    {
        foreach (PersonalClinicaSeeder::MEDICOS as $m) {
            if ($m[0] === $codigo) {
                return $this->usuario($m[8]);
            }
        }
        throw new RuntimeException("Médico desconocido: {$codigo}.");
    }

    /** Ficha HABILITADA de ese médico y especialidad, en la semana, día y hora indicados. */
    private function ficha(string $medico, string $especialidad, int $semana, int $dia, string $hora): int
    {
        $fecha = Siembra::ancla()->addWeeks($semana)->addDays($dia - 1)->toDateString();

        return (int) (DB::table('fichas as f')
            ->join('horarios_atencion as h', 'h.id', '=', 'f.horario_atencion_id')
            ->join('medicos_especialidades as me', 'me.id', '=', 'h.medico_especialidad_id')
            ->join('medicos as m', 'm.id', '=', 'me.medico_id')
            ->join('especialidades as e', 'e.id', '=', 'me.especialidad_id')
            ->where('m.codigo', $medico)->where('e.nombre', $especialidad)
            ->where('f.fecha', $fecha)->where('f.hora_inicio', $hora.':00')->where('f.estado', 'HABILITADA')
            ->value('f.id')
            ?? throw new RuntimeException("No hay ficha HABILITADA de {$medico} ({$especialidad}) el {$fecha} a las {$hora}."));
    }
}
