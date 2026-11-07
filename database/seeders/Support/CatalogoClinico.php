<?php

namespace Database\Seeders\Support;

/**
 * Conocimiento clínico de la siembra: diagnósticos CIE-10 por especialidad y
 * motivo de la cita, sus tratamientos y exámenes habituales, signos vitales
 * coherentes con la edad y valores de laboratorio generados a partir del rango
 * de referencia que corresponde al paciente.
 *
 * Todo el azar sale de mt_rand: el llamador fija la semilla (determinista).
 * Son datos de demostración con medicamentos y códigos reales, no una guía médica.
 */
final class CatalogoClinico
{
    /**
     * clave => [
     *   cie10, diagnóstico, [edad mín, edad máx] (años), sexo|null, perfil de signos (normal|fiebre|hta),
     *   anamnesis, examen físico, indicaciones,
     *   exámenes [[código, % de probabilidad]], perfil de laboratorio|null,
     *   tratamiento|null: [descripción, crónico, prescripciones [[medicamento, dosis, frecuencia, días, vía]],
     *                      alternativas [medicamento => prescripción|null (null = se omite)]]
     * ]
     */
    public const DIAGNOSTICOS = [
        // ------------------------------------------------------------- Pediatría
        'PED_RESFRIO' => ['J00', 'Rinofaringitis aguda (resfriado común)', [0, 17], null, 'fiebre',
            'Cuadro de {d} días de congestión nasal, rinorrea hialina y tos seca; fiebre no cuantificada.',
            'Faringe levemente congestiva, sin exudado. Murmullo vesicular conservado, sin ruidos agregados.',
            'Lavados nasales con suero fisiológico, abundantes líquidos y control si la fiebre dura más de 3 días.',
            [], null,
            ['Manejo sintomático de resfriado común', false, [['Paracetamol', '15 mg/kg/dosis (jarabe 120 mg/5 mL)', 'Cada 6 horas si hay fiebre', 3, 'Oral']], []]],
        'PED_AMIGDALITIS' => ['J03.9', 'Amigdalitis aguda, no especificada', [3, 17], null, 'fiebre',
            'Fiebre de {d} días, odinofagia y disminución del apetito. Sin tos.',
            'Amígdalas hipertróficas con exudado blanquecino; adenopatías cervicales dolorosas.',
            'Completar el antibiótico aunque mejore; dieta blanda y líquidos fríos.',
            [['HEMOGRAMA', 30]], null,
            ['Antibioticoterapia por amigdalitis aguda', false, [
                ['Amoxicilina', '50 mg/kg/día dividido en 3 tomas', 'Cada 8 horas', 10, 'Oral'],
                ['Paracetamol', '15 mg/kg/dosis', 'Cada 6 horas si hay fiebre', 3, 'Oral'],
            ], ['Amoxicilina' => ['Azitromicina', '10 mg/kg/día', 'Cada 24 horas', 3, 'Oral']]]],
        'PED_DIARREA' => ['A09', 'Diarrea y gastroenteritis de presunto origen infeccioso', [0, 17], null, 'normal',
            'Deposiciones líquidas ({d} por día) desde hace 2 días, sin sangre; tolera la vía oral.',
            'Mucosas húmedas, llenado capilar menor de 2 segundos. Abdomen blando, ruidos hidroaéreos aumentados.',
            'Rehidratación oral fraccionada, continuar la alimentación y volver si hay signos de deshidratación.',
            [], null,
            ['Rehidratación oral y zinc', false, [
                ['Sales de rehidratación oral', '50 a 100 mL/kg en 4 horas', 'Después de cada deposición líquida', 3, 'Oral'],
                ['Sulfato de zinc', '20 mg', 'Cada 24 horas', 10, 'Oral'],
            ], []]],
        'PED_SANO' => ['Z00.1', 'Control de salud de rutina del niño', [0, 17], null, 'normal',
            'Control programado. Sin molestias; alimentación y desarrollo acordes a la edad.',
            'Buen estado general. Peso y talla en percentiles adecuados. Examen físico sin alteraciones.',
            'Continuar la alimentación balanceada y el esquema de vacunación. Próximo control en 6 meses.',
            [['HEMOGRAMA', 25]], null, null],
        'PED_ANEMIA' => ['D50.9', 'Anemia por deficiencia de hierro, sin otra especificación', [1, 17], null, 'normal',
            'La madre refiere palidez y cansancio desde hace {d} semanas; dieta pobre en carnes.',
            'Palidez de piel y mucosas. Soplo sistólico funcional leve. Sin visceromegalias.',
            'Dieta rica en hierro (carnes, legumbres), hierro lejos de los lácteos y control con hemograma.',
            [['HEMOGRAMA', 100]], 'anemia',
            ['Ferroterapia oral por anemia ferropénica', false, [['Sulfato ferroso', '3 mg/kg/día de hierro elemental', 'Cada 24 horas, en ayunas', 30, 'Oral']], []]],
        'PED_ASMA' => ['J45.9', 'Asma, no especificada', [5, 17], null, 'normal',
            'Tos nocturna y sibilancias de {d} días, desencadenadas por el frío.',
            'Sibilancias espiratorias difusas, sin tiraje. Saturación adecuada.',
            'Técnica de inhalación con aerocámara; evitar humo y cambios bruscos de temperatura.',
            [], null,
            ['Broncodilatador de rescate', false, [['Salbutamol', '100 mcg, 2 inhalaciones con aerocámara', 'Cada 6 horas', 7, 'Inhalatoria']], []]],

        // ------------------------------------------------------ Medicina General
        'MG_FARINGITIS' => ['J02.9', 'Faringitis aguda, no especificada', [12, 120], null, 'fiebre',
            'Dolor de garganta y malestar general de {d} días, con fiebre.',
            'Faringe hiperémica sin exudado. Sin adenopatías significativas.',
            'Reposo relativo, líquidos abundantes y gárgaras de agua tibia con sal.',
            [], null,
            ['Manejo sintomático de faringitis', false, [['Paracetamol', '1 g (2 tabletas de 500 mg)', 'Cada 8 horas', 5, 'Oral']], []]],
        'MG_GASTRITIS' => ['K29.7', 'Gastritis, no especificada', [15, 120], null, 'normal',
            'Epigastralgia urente de {d} semanas, que empeora con el ayuno y el café.',
            'Abdomen blando, dolor leve a la palpación en epigastrio, sin signos de irritación peritoneal.',
            'Comidas fraccionadas; evitar picantes, café, alcohol y antiinflamatorios.',
            [], null,
            ['Inhibidor de bomba de protones', false, [['Omeprazol', '20 mg', 'Cada 24 horas, en ayunas', 28, 'Oral']], []]],
        'MG_LUMBAGO' => ['M54.5', 'Lumbago no especificado', [18, 120], null, 'normal',
            'Dolor lumbar mecánico de {d} días tras levantar peso; sin irradiación.',
            'Contractura paravertebral lumbar; Lasègue negativo; fuerza y reflejos conservados.',
            'Calor local, pausas activas e higiene postural. Volver si aparece dolor irradiado.',
            [], null,
            ['Analgesia por lumbalgia mecánica', false, [['Ibuprofeno', '400 mg', 'Cada 8 horas con alimentos', 5, 'Oral']],
                ['Ibuprofeno' => ['Paracetamol', '1 g', 'Cada 8 horas', 5, 'Oral']]]],
        'MG_CEFALEA' => ['R51', 'Cefalea', [12, 120], null, 'normal',
            'Cefalea frontal opresiva de {d} días, de predominio vespertino, sin signos de alarma.',
            'Examen neurológico sin alteraciones. Fondo de ojo normal.',
            'Dormir 7 a 8 horas, hidratación y registro de los episodios.',
            [], null,
            ['Analgesia por cefalea tensional', false, [['Paracetamol', '1 g', 'Cada 8 horas si hay dolor', 3, 'Oral']], []]],
        'MG_MIGRANA' => ['G43.9', 'Migraña, no especificada', [15, 70], null, 'normal',
            'Cefalea pulsátil hemicraneal con fotofobia y náuseas; {d} episodios este mes.',
            'Examen neurológico normal entre las crisis.',
            'Evitar desencadenantes (ayuno, desvelo) y llevar un diario de cefaleas.',
            [], null,
            ['Tratamiento de las crisis de migraña', false, [['Naproxeno', '550 mg', 'Cada 12 horas si hay dolor', 5, 'Oral']],
                ['Naproxeno' => ['Paracetamol', '1 g', 'Cada 8 horas si hay dolor', 5, 'Oral']]]],
        'MG_HTA' => ['I10', 'Hipertensión esencial (primaria)', [25, 120], null, 'hta',
            'Control de presión arterial. Cefalea ocasional; registros domiciliarios elevados en las últimas {d} semanas.',
            'Ruidos cardíacos rítmicos, sin soplos. Pulsos periféricos presentes. Sin edemas.',
            'Dieta baja en sal, actividad física 30 minutos al día y registro de la presión en casa.',
            [['CREATININA', 50], ['PERFIL_LIPIDICO', 40], ['EGO', 20]], null,
            ['Antihipertensivo de mantenimiento', true, [['Losartán', '50 mg', 'Cada 24 horas', 30, 'Oral']], []]],
        'MG_DM2' => ['E11.9', 'Diabetes mellitus tipo 2 sin complicaciones', [25, 120], null, 'normal',
            'Control de diabetes. Refiere sed y poliuria ocasional; adherencia irregular a la dieta.',
            'Sobrepeso. Pies sin lesiones, sensibilidad conservada. Resto sin alteraciones.',
            'Plan alimentario sin azúcares simples, caminata diaria y control de glucosa en ayunas.',
            [['GLUCOSA', 90], ['HBA1C', 70], ['CREATININA', 30]], 'diabetes',
            ['Hipoglucemiante oral', true, [['Metformina', '850 mg', 'Cada 12 horas con las comidas', 30, 'Oral']], []]],
        'MG_CERTIFICADO' => ['Z02.7', 'Expedición de certificado médico', [18, 120], null, 'normal',
            'Solicita certificado médico de salud para trámite laboral. Asintomático.',
            'Buen estado general. Examen físico sin alteraciones.',
            'Se extiende el certificado de buena salud.',
            [['HEMOGRAMA', 30], ['GLUCOSA', 30], ['EGO', 20]], null, null],
        'MG_ITU' => ['N39.0', 'Infección de vías urinarias, sitio no especificado', [15, 120], 'F', 'normal',
            'Disuria, polaquiuria y urgencia miccional de {d} días, sin fiebre.',
            'Dolor suprapúbico leve; puño percusión lumbar negativa.',
            'Ingesta abundante de líquidos y no retener la orina.',
            [['EGO', 100]], 'itu',
            ['Antibioticoterapia por cistitis no complicada', false, [['Nitrofurantoína', '100 mg', 'Cada 12 horas', 5, 'Oral']], []]],
        'MG_DISLIPIDEMIA' => ['E78.5', 'Hiperlipidemia, no especificada', [30, 120], null, 'normal',
            'Control de colesterol elevado detectado en un chequeo laboral hace {d} meses.',
            'Sobrepeso leve. Sin xantomas. Examen cardiovascular normal.',
            'Dieta baja en grasas saturadas, actividad física y control del perfil lipídico en 3 meses.',
            [['PERFIL_LIPIDICO', 100]], 'dislipidemia',
            ['Hipolipemiante', true, [['Atorvastatina', '20 mg', 'Cada 24 horas, por la noche', 30, 'Oral']], []]],
        'MG_HIPOTIROIDISMO' => ['E03.9', 'Hipotiroidismo, no especificado', [25, 120], 'F', 'normal',
            'Cansancio, intolerancia al frío y aumento de peso de {d} meses.',
            'Piel seca; tiroides no palpable; reflejos levemente lentos.',
            'Tomar la levotiroxina en ayunas, 30 minutos antes del desayuno; control de TSH en 6 semanas.',
            [['TSH', 100]], 'hipotiroidismo',
            ['Reemplazo hormonal tiroideo', true, [['Levotiroxina', '50 mcg', 'Cada 24 horas, en ayunas', 30, 'Oral']], []]],
        'MG_RINITIS' => ['J30.4', 'Rinitis alérgica, no especificada', [5, 120], null, 'normal',
            'Estornudos, prurito nasal y rinorrea acuosa de {d} semanas, peor en las mañanas.',
            'Cornetes pálidos y edematosos. Sin fiebre.',
            'Evitar el polvo y los alérgenos conocidos; lavados nasales.',
            [], null,
            ['Antihistamínico', false, [['Loratadina', '10 mg', 'Cada 24 horas', 10, 'Oral']], []]],

        // ------------------------------------------------------------ Geriatría
        'GER_CONTROL' => ['Z00.0', 'Examen médico general', [60, 120], null, 'normal',
            'Control geriátrico periódico. Refiere buen apetito; duerme bien.',
            'Buen estado general para la edad. Sin edemas. Marcha estable.',
            'Mantener la actividad física, hidratación y controles periódicos.',
            [['HEMOGRAMA', 40], ['GLUCOSA', 40], ['CREATININA', 40]], null, null],
        'GER_HTA' => ['I10', 'Hipertensión esencial (primaria)', [60, 120], null, 'hta',
            'Control de hipertensión; mareos ocasionales al levantarse en las últimas {d} semanas.',
            'Ruidos cardíacos rítmicos; sin soplos; pulsos pedios presentes.',
            'Dieta hiposódica; levantarse despacio; registro de la presión arterial.',
            [['CREATININA', 60], ['PERFIL_LIPIDICO', 40]], null,
            ['Antihipertensivo de mantenimiento', true, [['Enalapril', '10 mg', 'Cada 12 horas', 30, 'Oral']], []]],
        'GER_DM2' => ['E11.9', 'Diabetes mellitus tipo 2 sin complicaciones', [60, 120], null, 'normal',
            'Control de diabetes; refiere glucometrías elevadas.',
            'Pies sin úlceras; monofilamento con sensibilidad conservada.',
            'Plan alimentario, cuidado de los pies y control de glucosa.',
            [['GLUCOSA', 90], ['HBA1C', 70], ['CREATININA', 50]], 'diabetes',
            ['Hipoglucemiante oral', true, [['Metformina', '850 mg', 'Cada 12 horas con las comidas', 30, 'Oral']], []]],
        'GER_GONARTROSIS' => ['M17.9', 'Gonartrosis, no especificada', [55, 120], null, 'normal',
            'Dolor en ambas rodillas al caminar y subir gradas desde hace {d} meses.',
            'Crepitación en rodillas, sin derrame; rango de movimiento algo limitado.',
            'Control del peso, ejercicios de fortalecimiento de cuádriceps y calzado adecuado.',
            [], null,
            ['Analgesia y antiinflamatorio tópico por artrosis', false, [
                ['Paracetamol', '1 g', 'Cada 8 horas', 15, 'Oral'],
                ['Diclofenaco', 'Gel al 1 %, una capa fina sobre la rodilla', 'Cada 12 horas', 10, 'Tópica'],
            ], ['Diclofenaco' => null]]],
        'GER_RECETA' => ['Z76.0', 'Consulta para repetición de receta', [60, 120], null, 'normal',
            'Acude a revisar su medicación habitual; sin efectos adversos.',
            'Estable. Sin cambios respecto al control anterior.',
            'Se renueva la receta habitual y se revisa la forma de tomar cada medicamento.',
            [], null, null],
        'GER_MARCHA' => ['R26.8', 'Otras anormalidades de la marcha y de la movilidad', [60, 120], null, 'normal',
            'Inestabilidad al caminar; {d} caídas leves en el último año.',
            'Marcha a pasos cortos; Romberg negativo; fuerza 4/5 en miembros inferiores.',
            'Fisioterapia, retirar alfombras sueltas y usar bastón.',
            [['HEMOGRAMA', 20]], null, null],
        'GER_ANEMIA' => ['D64.9', 'Anemia, no especificada', [60, 120], null, 'normal',
            'Cansancio y disnea de esfuerzo de {d} semanas.',
            'Palidez de piel y mucosas; taquicardia leve; sin sangrado evidente.',
            'Dieta rica en hierro y control con hemograma.',
            [['HEMOGRAMA', 100]], 'anemia',
            ['Ferroterapia oral', false, [['Sulfato ferroso', '300 mg (60 mg de hierro elemental)', 'Cada 24 horas', 30, 'Oral']], []]],

        // ----------------------------------------------------------- Ginecología
        'GIN_CONTROL' => ['Z01.4', 'Examen ginecológico (general) (de rutina)', [12, 120], 'F', 'normal',
            'Control ginecológico anual. Ciclos regulares; sin molestias.',
            'Mamas sin nódulos. Genitales externos normales. Especuloscopía sin alteraciones.',
            'Autoexamen de mamas mensual y control anual.',
            [['EGO', 25]], null, null],
        'GIN_PRENATAL' => ['Z34.9', 'Supervisión de embarazo normal, no especificado', [16, 44], 'F', 'normal',
            'Amenorrea de {d} semanas; náuseas matutinas leves.',
            'Útero aumentado de tamaño acorde a la edad gestacional. Sin sangrado.',
            'Controles prenatales mensuales, alimentación balanceada y signos de alarma explicados.',
            [['BHCG', 70], ['HEMOGRAMA', 60], ['EGO', 50], ['GLUCOSA', 40]], 'embarazo',
            ['Suplementación prenatal', false, [
                ['Ácido fólico', '1 mg', 'Cada 24 horas', 30, 'Oral'],
                ['Sulfato ferroso', '300 mg (60 mg de hierro elemental)', 'Cada 24 horas', 30, 'Oral'],
            ], []]],
        'GIN_PLANIFICACION' => ['Z30.0', 'Consejo y asesoramiento general sobre la anticoncepción', [15, 49], 'F', 'normal',
            'Solicita orientación sobre métodos anticonceptivos. Sin contraindicaciones referidas.',
            'Presión arterial normal. Examen ginecológico sin alteraciones.',
            'Se explica el uso correcto y la conducta ante el olvido de una toma.',
            [['BHCG', 60]], null,
            ['Anticoncepción hormonal oral', true, [['Levonorgestrel + etinilestradiol', '0,15 mg / 0,03 mg (1 tableta)', 'Cada 24 horas', 28, 'Oral']], []]],
        'GIN_VAGINITIS' => ['N76.0', 'Vaginitis aguda', [15, 120], 'F', 'normal',
            'Flujo vaginal con mal olor de {d} días, sin fiebre.',
            'Flujo grisáceo homogéneo; sin dolor a la movilización cervical.',
            'Evitar duchas vaginales y alcohol durante el tratamiento.',
            [], null,
            ['Tratamiento de vaginosis', false, [['Metronidazol', '500 mg', 'Cada 12 horas', 7, 'Oral']], []]],
        'GIN_DISMENORREA' => ['N94.6', 'Dismenorrea, no especificada', [12, 45], 'F', 'normal',
            'Dolor pélvico tipo cólico los primeros {d} días de la menstruación.',
            'Abdomen blando; examen ginecológico sin hallazgos.',
            'Calor local y actividad física regular.',
            [], null,
            ['Analgesia para dismenorrea', false, [['Ibuprofeno', '400 mg', 'Cada 8 horas durante la menstruación', 3, 'Oral']],
                ['Ibuprofeno' => ['Paracetamol', '1 g', 'Cada 8 horas durante la menstruación', 3, 'Oral']]]],
        'GIN_VULVITIS' => ['N76.2', 'Vulvitis aguda', [0, 11], 'F', 'normal',
            'La madre refiere prurito y enrojecimiento vulvar de {d} días; sin flujo abundante.',
            'Eritema vulvar leve, sin lesiones ni cuerpos extraños.',
            'Higiene con agua tibia, ropa interior de algodón y evitar jabones perfumados.',
            [], null,
            ['Cuidado local de la vulvitis', false, [['Óxido de zinc', 'Pomada al 10 %, capa fina', 'Después de cada cambio o baño', 7, 'Tópica']], []]],
        'GIN_MENOPAUSIA' => ['N95.1', 'Estados menopáusicos y climatéricos femeninos', [45, 120], 'F', 'normal',
            'Bochornos y alteraciones del sueño desde hace {d} meses; última menstruación hace más de un año.',
            'Mucosa vaginal levemente atrófica. Mamas sin nódulos.',
            'Medidas generales para los bochornos, calcio en la dieta y actividad física.',
            [['TSH', 30]], null, null],

        // ----------------------------------------------------------- Odontología
        'OD_CARIES' => ['K02.1', 'Caries de la dentina', [2, 120], null, 'normal',
            'Sensibilidad al frío y a los dulces en una pieza posterior desde hace {d} semanas.',
            'Lesión cariosa en dentina en pieza posterior; sin compromiso pulpar.',
            'Cepillado tres veces al día con pasta fluorada e hilo dental.',
            [], null,
            ['Restauración con resina compuesta', false, [], []]],
        'OD_GINGIVITIS' => ['K05.1', 'Gingivitis crónica', [6, 120], null, 'normal',
            'Sangrado de encías al cepillarse desde hace {d} semanas.',
            'Encías inflamadas con placa bacteriana y cálculo supragingival.',
            'Se realiza profilaxis; técnica de cepillado e hilo dental.',
            [], null,
            ['Profilaxis y antiséptico bucal', false, [['Clorhexidina', 'Enjuague al 0,12 %, 15 mL durante 30 segundos', 'Cada 12 horas', 10, 'Bucal']], []]],
        'OD_ABSCESO' => ['K04.7', 'Absceso periapical sin fístula', [12, 120], null, 'normal',
            'Dolor intenso y pulsátil en una muela de {d} días, con aumento de volumen.',
            'Pieza con caries profunda, dolor a la percusión y tumefacción vestibular.',
            'Drenaje y apertura cameral; se programa la endodoncia o la extracción.',
            [], null,
            ['Antibiótico y analgesia por absceso dental', false, [
                ['Amoxicilina', '500 mg', 'Cada 8 horas', 7, 'Oral'],
                ['Ibuprofeno', '400 mg', 'Cada 8 horas', 3, 'Oral'],
            ], [
                'Amoxicilina' => ['Clindamicina', '300 mg', 'Cada 8 horas', 7, 'Oral'],
                'Ibuprofeno' => ['Paracetamol', '1 g', 'Cada 8 horas', 3, 'Oral'],
            ]]],
        'OD_REVISION' => ['Z01.2', 'Examen odontológico', [0, 120], null, 'normal',
            'Revisión odontológica periódica. Sin molestias.',
            'Piezas dentarias sin caries activas; encías sanas.',
            'Cepillado e hilo dental; próximo control en 6 meses.',
            [], null, null],
        'OD_ORTODONCIA' => ['K07.3', 'Anomalías de la posición del diente', [8, 60], null, 'normal',
            'Control de ortodoncia; sin molestias ni brackets despegados.',
            'Aparatología fija en buen estado; se activa el arco.',
            'Evitar alimentos duros y pegajosos; control en 4 semanas.',
            [], null, null],

        // ----------------------------------------------------------- Cardiología
        'CAR_HTA' => ['I10', 'Hipertensión esencial (primaria)', [25, 120], null, 'hta',
            'Control cardiológico de hipertensión; palpitaciones ocasionales hace {d} semanas.',
            'Ruidos cardíacos rítmicos; segundo ruido aórtico reforzado. Sin edemas.',
            'Dieta hiposódica, actividad física y control de la presión arterial en casa.',
            [['PERFIL_LIPIDICO', 60], ['CREATININA', 50]], null,
            ['Antihipertensivo de mantenimiento', true, [['Amlodipino', '5 mg', 'Cada 24 horas', 30, 'Oral']], []]],
        'CAR_PALPITACIONES' => ['R00.2', 'Palpitaciones', [10, 120], null, 'normal',
            'Palpitaciones de segundos de duración, {d} veces por semana, sin síncope.',
            'Ritmo regular; sin soplos. Electrocardiograma en ritmo sinusal.',
            'Evitar el exceso de café y bebidas energéticas; volver si hay desmayo o dolor de pecho.',
            [['TSH', 60], ['HEMOGRAMA', 40]], null, null],
        'CAR_ARRITMIA' => ['I49.9', 'Arritmia cardíaca, no especificada', [40, 120], null, 'normal',
            'Palpitaciones irregulares de {d} semanas; disnea leve a grandes esfuerzos.',
            'Ritmo irregular a la auscultación; extrasístoles en el electrocardiograma.',
            'Se solicita Holter de 24 horas; control en un mes.',
            [], null,
            ['Control de la frecuencia cardíaca', true, [['Bisoprolol', '2,5 mg', 'Cada 24 horas', 30, 'Oral']], []]],
        'CAR_PESQUISA' => ['Z13.6', 'Examen de pesquisa especial para trastornos cardiovasculares', [0, 120], null, 'normal',
            'Evaluación cardiológica preventiva; antecedentes familiares cardiovasculares.',
            'Examen cardiovascular normal. Electrocardiograma sin alteraciones.',
            'Mantener hábitos saludables; control anual.',
            [['PERFIL_LIPIDICO', 40]], null, null],
        'CAR_SOPLO' => ['R01.0', 'Soplos cardíacos benignos y funcionales', [0, 17], null, 'normal',
            'Derivado por soplo detectado en el control pediátrico; asintomático.',
            'Soplo sistólico suave grado I/VI en el borde esternal izquierdo, que cambia con la posición.',
            'Soplo funcional: no requiere restricciones; control en un año.',
            [], null, null],
    ];

    /** Motivo de la cita => diagnósticos candidatos (repetir una clave le da más peso). */
    public const MOTIVOS = [
        'Control de niño sano' => ['PED_SANO', 'PED_SANO', 'PED_SANO', 'PED_ANEMIA'],
        'Fiebre y tos' => ['PED_RESFRIO', 'PED_RESFRIO', 'PED_AMIGDALITIS', 'PED_ASMA'],
        'Vacunación y control' => ['PED_SANO'],
        'Control de crecimiento' => ['PED_SANO', 'PED_SANO', 'PED_ANEMIA', 'PED_DIARREA'],
        'Consulta general' => ['MG_FARINGITIS', 'MG_GASTRITIS', 'MG_LUMBAGO', 'MG_ITU', 'MG_RINITIS', 'MG_DISLIPIDEMIA', 'MG_HIPOTIROIDISMO'],
        'Control de presión arterial' => ['MG_HTA', 'MG_HTA', 'MG_DISLIPIDEMIA'],
        'Dolor de cabeza persistente' => ['MG_CEFALEA', 'MG_MIGRANA', 'MG_HTA'],
        'Certificado médico' => ['MG_CERTIFICADO'],
        'Control de diabetes' => ['MG_DM2'],
        'Control geriátrico' => ['GER_CONTROL', 'GER_CONTROL', 'GER_HTA', 'GER_DM2', 'GER_ANEMIA'],
        'Revisión de medicación' => ['GER_RECETA', 'GER_HTA', 'GER_DM2'],
        'Evaluación de movilidad' => ['GER_GONARTROSIS', 'GER_MARCHA'],
        'Control ginecológico' => ['GIN_CONTROL', 'GIN_CONTROL', 'GIN_VAGINITIS', 'GIN_DISMENORREA', 'GIN_MENOPAUSIA', 'GIN_VULVITIS'],
        'Control prenatal' => ['GIN_PRENATAL'],
        'Consulta de planificación familiar' => ['GIN_PLANIFICACION'],
        'Limpieza dental' => ['OD_GINGIVITIS', 'OD_REVISION'],
        'Revisión dental' => ['OD_REVISION', 'OD_CARIES'],
        'Dolor de muela' => ['OD_CARIES', 'OD_ABSCESO'],
        'Ortodoncia: control' => ['OD_ORTODONCIA'],
        'Control cardiológico' => ['CAR_HTA', 'CAR_PESQUISA', 'CAR_ARRITMIA', 'CAR_SOPLO'],
        'Electrocardiograma de control' => ['CAR_PESQUISA', 'CAR_ARRITMIA'],
        'Palpitaciones' => ['CAR_PALPITACIONES', 'CAR_ARRITMIA'],
    ];

    /** Prefijo de las claves de cada especialidad (respaldo si el motivo no orienta el diagnóstico). */
    private const PREFIJOS = [
        'Pediatría' => 'PED_', 'Medicina General' => 'MG_', 'Geriatría' => 'GER_',
        'Ginecología' => 'GIN_', 'Odontología' => 'OD_', 'Cardiología' => 'CAR_',
    ];

    /** Decimales con que se informa cada parámetro numérico. */
    public const DECIMALES = [
        'HB' => 1, 'HTO' => 1, 'LEU' => 1, 'PLQ' => 0, 'GLU' => 0, 'COL' => 0, 'HDL' => 0, 'LDL' => 0, 'TG' => 0,
        'EGO_DENSIDAD' => 3, 'EGO_PH' => 1, 'EGO_LEU' => 0, 'CREA' => 2, 'TSH' => 2, 'HBA1C' => 1,
    ];

    /** Perfil de laboratorio del diagnóstico => interpretación o texto esperado por parámetro. */
    private const PERFILES = [
        'anemia' => ['HB' => 'BAJO', 'HTO' => 'BAJO'],
        'diabetes' => ['GLU' => 'ALTO', 'HBA1C' => 'ALTO'],
        'dislipidemia' => ['COL' => 'ALTO', 'LDL' => 'ALTO', 'TG' => 'ALTO'],
        'hipotiroidismo' => ['TSH' => 'ALTO'],
        'itu' => ['EGO_ASPECTO' => 'Turbio', 'EGO_NITRITOS' => 'Positivo', 'EGO_LEU' => 'ALTO'],
        'embarazo' => ['BHCG' => 'Positivo'],
    ];

    private const TEXTOS_NORMALES = [
        'EGO_COLOR' => ['Amarillo', 'Amarillo claro', 'Amarillo ámbar'],
        'EGO_ASPECTO' => ['Transparente', 'Transparente', 'Ligeramente turbio'],
        'EGO_NITRITOS' => ['Negativo'],
        'BHCG' => ['Negativo'],
    ];

    /** Clave de diagnóstico compatible con la especialidad, el motivo, la edad y el sexo. */
    public static function elegir(string $especialidad, ?string $motivo, int $edad, string $sexo): string
    {
        $prefijo = self::PREFIJOS[$especialidad] ?? throw new SiembraInvalida("Especialidad sin diagnósticos: {$especialidad}.");
        $compatible = fn (string $k) => str_starts_with($k, $prefijo)
            && self::DIAGNOSTICOS[$k][2][0] <= $edad && $edad <= self::DIAGNOSTICOS[$k][2][1]
            && (self::DIAGNOSTICOS[$k][3] === null || self::DIAGNOSTICOS[$k][3] === $sexo);

        $candidatos = array_values(array_filter(self::MOTIVOS[$motivo] ?? [], $compatible));
        if ($candidatos === []) {
            $candidatos = array_values(array_filter(array_keys(self::DIAGNOSTICOS), $compatible));
        }
        if ($candidatos === []) {
            throw new SiembraInvalida("Ningún diagnóstico de {$especialidad} es compatible con {$edad} años y sexo {$sexo}.");
        }

        return $candidatos[mt_rand(0, count($candidatos) - 1)];
    }

    /** Texto con {d} reemplazado por un número pequeño. */
    public static function texto(string $plantilla): string
    {
        return str_replace('{d}', (string) mt_rand(2, 5), $plantilla);
    }

    /**
     * Signos vitales coherentes con la edad y el sexo. Cochabamba está a 2 558 m:
     * la saturación normal es algo menor que a nivel del mar.
     *
     * @return array<string,int|float|null>
     */
    public static function signos(int $edad, string $sexo, string $perfil): array
    {
        $r = fn (float $a, float $b, int $dec = 0) => round($a + (mt_rand() / mt_getrandmax()) * ($b - $a), $dec);
        $mujer = $sexo === 'F';

        [$peso, $talla, $fc, $pas, $pad] = match (true) {
            $edad < 1 => [[4, 10], [55, 75], [110, 150], null, null],
            $edad < 3 => [[9, 15], [72, 95], [95, 130], null, null],
            $edad < 6 => [[13, 21], [92, 115], [85, 120], [90, 105], [55, 65]],
            $edad < 12 => [[19, 40], [112, 150], [70, 110], [95, 112], [58, 72]],
            $edad < 18 => [$mujer ? [42, 62] : [45, 70], $mujer ? [148, 165] : [152, 180], [62, 100], [100, 120], [62, 78]],
            $edad < 60 => [$mujer ? [50, 80] : [62, 95], $mujer ? [148, 168] : [160, 182], [60, 90], [105, 130], [65, 84]],
            default => [$mujer ? [48, 78] : [55, 85], $mujer ? [143, 160] : [155, 175], [58, 88], [115, 140], [68, 86]],
        };
        if ($perfil === 'hta' && $pas !== null) {
            [$pas, $pad] = [[142, 164], [90, 102]];
        }

        $signos = [
            'peso_kg' => $r($peso[0], $peso[1], 1),
            'talla_cm' => $r($talla[0], $talla[1], 1),
            'presion_sistolica' => $pas === null ? null : (int) $r($pas[0], $pas[1]),
            'presion_diastolica' => $pad === null ? null : (int) $r($pad[0], $pad[1]),
            'temperatura_c' => $perfil === 'fiebre' ? $r(38.0, 39.2, 1) : $r(36.2, 37.2, 1),
            'frecuencia_cardiaca' => (int) $r($fc[0], $fc[1]) + ($perfil === 'fiebre' ? 10 : 0),
            'saturacion_o2' => (int) $r(92, 97),
        ];

        return $signos;
    }

    /**
     * Interpretación buscada para un parámetro: la del perfil del diagnóstico o,
     * si no la fija, NORMAL casi siempre, con algún valor levemente alterado y,
     * muy de vez en cuando, uno crítico.
     */
    public static function objetivo(string $clave, ?string $perfil): string
    {
        $fijo = self::PERFILES[$perfil][$clave] ?? null;
        if ($fijo !== null) {
            return $fijo;
        }
        $n = mt_rand(1, 1000);

        return match (true) {
            $n <= 8 => 'CRITICO',
            $n <= 90 => mt_rand(0, 1) === 1 ? 'ALTO' : 'BAJO',
            default => 'NORMAL',
        };
    }

    /** Texto del resultado de un parámetro de tipo TEXTO. */
    public static function textoResultado(string $clave, ?string $perfil): string
    {
        $fijo = self::PERFILES[$perfil][$clave] ?? null;
        if ($fijo !== null) {
            return $fijo;
        }
        $opciones = self::TEXTOS_NORMALES[$clave];

        return $opciones[mt_rand(0, count($opciones) - 1)];
    }

    /**
     * Valor numérico que cae en la interpretación buscada según el rango. Si el
     * rango no permite esa interpretación (sin límite crítico o sin mínimo) se
     * genera uno NORMAL. La interpretación definitiva siempre la calcula
     * RegistroClinico::interpretar con el valor ya redondeado.
     */
    public static function valor(string $clave, object $rango, string $objetivo): float
    {
        $dec = self::DECIMALES[$clave];
        $paso = 10 ** -$dec;
        $u = fn (float $a, float $b) => round($a + (mt_rand() / mt_getrandmax()) * max(0, $b - $a), $dec);
        $min = $rango->valor_min === null ? null : (float) $rango->valor_min;
        $max = $rango->valor_max === null ? null : (float) $rango->valor_max;
        $cmin = $rango->critico_min === null ? null : (float) $rango->critico_min;
        $cmax = $rango->critico_max === null ? null : (float) $rango->critico_max;

        if ($objetivo === 'CRITICO') {
            $objetivo = $cmin !== null && ($cmax === null || mt_rand(0, 1) === 0) ? 'CRITICO_BAJO' : ($cmax !== null ? 'CRITICO_ALTO' : 'ALTO');
        }
        // Cuánto se aleja un valor alterado (no crítico): con mínimo y máximo, un 20–30 % del ancho del
        // rango (la densidad o el pH no admiten porcentajes del valor); si no, una fracción del límite.
        $ancho = $min !== null && $max !== null && $min > 0 ? $max - $min : null;
        $bajoDesde = $cmin !== null ? $cmin + $paso : ($ancho !== null ? $min - $ancho * 0.2 : ($min ?? 0) * 0.75);
        $altoHasta = self::LIMITE_ALTO[$clave]
            ?? ($ancho !== null ? $max + $ancho * 0.3 : ($max ?? 0) * 1.45);
        if ($cmax !== null) {
            $altoHasta = min($altoHasta, $cmax - $paso);
        }

        return match (true) {
            $objetivo === 'CRITICO_BAJO' && $cmin !== null => $u(max(0, $cmin * 0.8), $cmin - $paso),
            $objetivo === 'CRITICO_ALTO' && $cmax !== null => $u($cmax + $paso, $cmax * 1.2),
            $objetivo === 'BAJO' && $min !== null && $min > 0 => $u($bajoDesde, $min - $paso),
            $objetivo === 'ALTO' && $max !== null => $u($max + $paso, $altoHasta),
            default => $u($min ?? ($max * 0.55), $max ?? ($min * 1.6)),
        };
    }

    /** Techo de los valores ALTO de los parámetros cuyo valor patológico típico supera mucho el límite. */
    private const LIMITE_ALTO = ['GLU' => 260, 'HBA1C' => 10.5, 'TSH' => 15, 'EGO_LEU' => 40, 'TG' => 400, 'LDL' => 210, 'COL' => 290];
}
