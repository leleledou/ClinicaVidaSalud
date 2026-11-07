# Decisiones de los seeders — Clínica Vida Salud (Ciclo 1 y datos clínicos del Ciclo 2)

## Estado de verificación (leer primero)

**Actualización (07/11/2026):** los seeders ya se ejecutaron en PostgreSQL local con `migrate:fresh --seed` (perfil extendido), sin errores. Después se repitió `db:seed` dos veces sobre la misma base sin ningún duplicado. Las comprobaciones SQL de los datos clínicos están en §18.5. El resto de esta sección describe la verificación previa, hecha en memoria antes de tener PostgreSQL.

Antes de esa ejecución, lo verificado era:

- **Estático:** `php -l` de todos los archivos, carga de clases, nombres de tablas y columnas contra las migraciones, conteos de catálogos y solapes de horarios.
- **Simulación en memoria (SQLite `:memory:`, sin PostgreSQL):** se cargaron las 40 migraciones reales, se ejecutaron los tres perfiles y sus repeticiones, y se validaron los datos. Evaluó **78 de los 79 CHECK reales** (el restante, `ck_horarios_duracion_divide`, se comprobó aparte), claves foráneas, unicidad de documentos y de citas activas por ficha, y toda UPDATE/DELETE de la siembra estaba bloqueada por triggers de prueba.
- **Lo que la simulación NO prueba:** restricciones EXCLUDE, triggers y funciones de PostgreSQL, ni el comportamiento exacto de tipos (`time`, `timestamp`, `boolean`). Eso solo se confirma en `clinica_vida_salud_verificacion`.

Las cantidades de §9 proceden de la simulación en memoria y de la construcción de los datos; deben **reconfirmarse** tras la primera ejecución autorizada en PostgreSQL.

## 1. Objetivo

Poblar la base definida por las 40 migraciones con datos ficticios, coherentes y reproducibles que permitan demostrar los 20 casos de uso del Ciclo 1 (CU01–CU20), sin saltarse restricciones, triggers ni reglas de negocio.

## 2. Fuentes y prioridad

| Prioridad | Fuente | Uso |
|---|---|---|
| 1 | `database/migrations/` | nombres físicos, tipos, CHECK, únicos, triggers |
| 2 | `docs/uml/ClinicaVidaSalud_Final.mdj` | significado de clases y asociaciones (34 clases, incluida `RolPermiso`) |
| 3 | `docs/requisitos/ClinicaVidaSalud_Examen2_Ciclo1.docx` (PUDS-UML) | escenarios, reglas RN01–RN25, cantidades de §6.3.2.4 |
| 4 | `docs/decisiones-implementacion.md` | decisiones técnicas aprobadas |

El UML modela `RolPermiso` como clase asociativa; la implementación usa la tabla intermedia `roles_permisos` (PK compuesta). No se creó otra tabla. El UML contiene además una asociación sin nombre entre Permiso y Rol, aparte de `OTORGA`: es un residuo de representación y no afecta a los seeders.

## 3. Seeders

Todos usan `DB::table()` (aún no hay modelos Eloquent), transacciones y claves naturales. Ninguno desactiva triggers ni restricciones, ni hace `UPDATE` ni `DELETE`.

| Seeder | Tablas | Notas |
|---|---|---|
| `CatalogosSeeder` | — | orquesta los seis siguientes |
| `SeguridadSeeder` | `roles`, `permisos`, `roles_permisos` | agrupados: la intermedia exige ambos catálogos |
| `ConfiguracionSistemaSeeder` | `configuraciones_sistema` | `insertOrIgnore`: no pisa valores ya cambiados |
| `EspecialidadSeeder`, `AseguradoraSeeder`, `ConsultorioSeeder` | sus tablas | |
| `LaboratorioCatalogoSeeder` | `tipos_examen`, `parametros_laboratorio`, `rangos_referencia` | catálogo de laboratorio (§18.1) |
| `UsuarioInicialSeeder` | `personas`, `administradores`, `usuarios` | único seeder de cuentas permitido en producción (ver §12) |
| `Ciclo1DemoSeeder` | — | orquesta los cuatro siguientes |
| `PersonalClinicaSeeder` | personas, administradores, recepcionistas, médicos, `medicos_especialidades`, usuarios | |
| `PacienteDemoSeeder` | personas, pacientes, `historias_clinicas`, `vinculos_paciente`, `seguros_paciente`, usuarios | `historias_clinicas` se incluye porque CU10 la abre al registrar |
| `AgendaSeeder` | `horarios_atencion`, `bloqueos_agenda`, `fichas` | |
| `CitaDemoSeeder` | `citas`, `historiales_cita` | |
| `ClinicaDemoSeeder` | antecedentes, alergias, consultas, laboratorio, tratamientos (+ 8 citas) | escenario clínico de la demo (§18.2) |
| `PoblacionExtendidaSeeder` | las anteriores | **opcional**; nunca desde el perfil mínimo |
| `ClinicaExtendidaSeeder` | antecedentes, alergias, consultas, laboratorio, tratamientos | atención de la población extendida (§18.1) |
| `NotificacionSeeder` | `notificaciones` | derivada de citas y resultados; demo y extendido |
| `BitacoraSeeder` | `bitacoras` | derivada de todo lo anterior; demo y extendido |
| `DatabaseSeeder` | — | elige el perfil con `SEED_PERFIL` |

Apoyo en `database/seeders/Support/`: `Siembra` (reloj y zona, búsqueda e inserción idempotente, contraseñas, guarda de producción), `AgendaFichas`, `RegistroCitas` (cita + historial, con validación previa), `RegistroClinico` (consulta, orden, resultados y tratamiento, con validación previa), `CatalogoClinico` (diagnósticos, tratamientos, signos vitales y valores de laboratorio) y `SiembraInvalida`.

El historial de una cita se inserta junto con ella porque `historiales_cita` es de solo inserción.

## 4. Orden de ejecución

- **mínimo:** Seguridad → Configuración → Especialidad → Aseguradora → Consultorio → Laboratorio → Usuario inicial.
- **demo:** + Personal → Pacientes → Agenda (horarios → bloqueos → fichas) → Citas → Clínica demo → Notificaciones → Bitácora.
- **extendido:** + Clínica demo → pacientes → fichas de las semanas −8 a +2 → citas → Clínica extendida → Notificaciones → Bitácora.

Notificaciones y bitácora siempre van al final porque se derivan de todo lo sembrado antes.

La simulación confirmó que cada seeder lanzado directamente falla con un mensaje claro si falta su dependencia, y que ninguno invoca otro perfil.

## 5. Roles y permisos

Roles obligatorios: `ADMINISTRADOR`, `RECEPCIONISTA`, `MEDICO`, `PACIENTE`. La demo **no** añade roles: los escenarios de baja lógica (CU05 A1) se prueban aparte, de forma aislada y reversible (ver §6b).

El documento menciona 41 permisos y 58 asignaciones pero solo nombra 13 códigos. El catálogo es **provisional**: 13 «doc» y 28 derivados; **52 asignaciones** (A 18, R 15, M 12, P 7). No se fuerzan 58; el documento no enumera las suyas, por lo que la diferencia no es auditable. Ningún rol recibe todos los permisos y todos están asignados.

| Módulo | Permisos (origen: **d** documento, **v** derivado) | CU / requisito | Roles |
|---|---|---|---|
| seguridad | usuarios.gestionar **d**, roles.gestionar **d**, bitacora.consultar **v**, configuracion.gestionar **v** | CU04, CU05, RF bitácora, RF parámetros | A |
| catálogos | especialidades.gestionar **d**, medicos.gestionar **d**, consultorios.gestionar **d**, aseguradoras.gestionar **v** | CU06, CU07, CU08, RF aseguradoras | A |
| agenda | horarios.gestionar **v**, bloqueos.gestionar **v** | CU09, RF bloqueos | A |
| agenda | fichas.consultar **v** | CU14 | A R M P |
| agenda | agenda.consultar **d** / agenda.propia **d** | CU19 | A R / M |
| pacientes | pacientes.gestionar **d** | CU10 | R |
| pacientes | pacientes.buscar **d** | CU11 | A R M |
| pacientes | perfil.gestionar **v** | CU12 | P |
| pacientes | vinculos.gestionar/verificar **v**, seguros.gestionar/verificar **v** | CU13, RF seguros | R |
| citas | citas.programar **d**, citas.reprogramar **v**, citas.cancelar **v**, citas.confirmar **d** | CU15, CU16, CU17, CU18 | R |
| citas | citas.registrar_asistencia **d** | CU18 (R-MED) | R M |
| citas | citas.autoservicio **d** | CU15–CU17 | P |
| citas | citas.autorizar_excepcion **v** | CU15 (excepción) | A |
| citas | citas.historial_consultar **v** / citas.historial_propio **v** | CU20 | A R M / P |
| clínica (Ciclo 2) | historia.consultar/gestionar, consultas.gestionar, tratamientos.gestionar **v** | atención clínica | M |
| clínica (Ciclo 2) | historia.propia, resultados.propios **v** | atención clínica, laboratorio | P |
| laboratorio (Ciclo 2) | laboratorio.gestionar **v** / cargar_resultados, consultar_resultados **v** | laboratorio | A / M |
| transversales | notificaciones.gestionar **v**, reportes.consultar **v** | notificaciones, RF13 | A |
| transversales | voz.usar **v** | comandos de voz | R M P |

Observaciones: `citas.historial_consultar` para el médico excede lo que dice el documento (pacientes con los que tiene o tuvo cita): **el servicio deberá limitarlo**. `fichas.consultar` es público en el documento; aquí se asigna a los cuatro roles porque el visitante no tiene rol.

## 6. Zona horaria (política de fechas y horas)

- Las columnas `timestamp(0)`, `date` y `time` de PostgreSQL **no guardan zona**. Los seeders escriben siempre **hora local de la clínica (`America/La_Paz`, UTC−4, sin horario de verano)**, nunca UTC.
- Todo instante se calcula con zona explícita (`Siembra::ahora()`, `Siembra::instante()`): la comparación pasado/futuro y las horas de las fichas (hora local) usan el mismo reloj. Se puede cambiar con `SEED_ZONA_HORARIA`.
- Los seeders nunca dependen del `DEFAULT CURRENT_TIMESTAMP` de la base: cada timestamp se envía explícito.
- **Decisión pendiente de su aprobación:** `config/app.php` fija `'timezone' => 'UTC'`. No se modificó. Si la aplicación se queda en UTC, `now()` de Laravel y los `useCurrent()` de las migraciones producirán horas UTC mezcladas con las horas locales sembradas (diferencia de 4 horas en cualquier cita o ficha creada desde la web). Recomendación: fijar `America/La_Paz` en la configuración **antes** de crear servicios que escriban fechas, y confirmar la zona de sesión de PostgreSQL. Cambiarla globalmente afecta a todo lo que use `now()`, `Carbon` o el programador de tareas.

## 6b. Escenarios de baja lógica (rol y especialidad)

Los catálogos aprobados (4 roles y 6 especialidades) no se alteran en ningún perfil. Los escenarios CU05 A1 y CU06 A2 se prueban **después**, con una prueba aislada y reversible: dentro de una transacción se crea un rol y una especialidad temporales con estado `INACTIVO` (o se desactiva uno sin usuarios ni médicos), se verifica el comportamiento y la transacción se revierte (`ROLLBACK`). No queda ningún dato permanente. Esa prueba pertenece a la fase de servicios y pruebas de Laravel, no a los seeders.

## 7. Configuración inicial (10 claves)

Respaldadas por el documento: `max_reprogramaciones`=2, `edad_mayoria`=18, `tolerancia_inasistencia_min`=15, `max_inasistencias_autoreserva`=3, `meses_inasistencias`=6, `horas_minimas_cambio_web`=12.
**Propuestas** (`[PROPUESTA]` en la descripción): `max_intentos_login`=5, `minutos_bloqueo_login`=15, `minutos_inactividad_sesion`=30, `semanas_generacion_fichas`=4.

## 8. Especialidades

| Especialidad | Edad | Sexo | Ficha | Respaldo |
|---|---|---|---|---|
| Medicina General | 18–59 | — | 20 min | edad: documento |
| Pediatría | 0–17 | — | 30 min | edad: documento |
| Geriatría | 60+ | — | 30 min | edad: documento |
| Ginecología | — | F | 30 min | sexo: documento |
| Odontología | — | — | 30 min | **supuesto** |
| Cardiología | — | — | 30 min | **supuesto** |

Supuestos a confirmar con la docente: Odontología y Cardiología sin restricción, Ginecología sin edad mínima y las duraciones. La demo no añade especialidades; la baja lógica (CU06 A2) se prueba aparte (§6b).

## 9. Escenarios y cantidades

**Personal:** 3 recepcionistas, 12 médicos (14 relaciones; Pérez y Gutiérrez con dos especialidades), 2 administradores (`ADM-001` inicial, `ADM-002` demo). La Dra. Camacho está inactiva con horarios cerrados e historial.

**Pacientes demo (17):**

| Código | Escenario |
|---|---|
| PAC-000001/2/3 | Pedro (8, solo certificado), Sofía (5, sin documento, fecha de nacimiento **fija**), Lucas (17 en la semana 0, 18 desde la +1); responsables legales verificados |
| PAC-000004 | Carlos: 3 inasistencias, reserva en línea bloqueada (RN19) |
| PAC-000005/7/12 | Luciana, Valeria, Camila: autorregistro con correo verificado |
| PAC-000006 | Andrés: sin cuenta ni seguro, documento con complemento |
| PAC-000008/9 | Fernanda (seguro vigente) y Mario (seguro vencido, contacto sin verificar) |
| PAC-000010 | Dr. Pérez como paciente (misma persona que `MED-001`) |
| PAC-000011 | Ricardo: seguro vigente, reasignación a otro médico |
| PAC-000013 | Gabriel: **cuenta bloqueada** temporalmente (5 intentos; bloqueo de demostración de 14 días) |
| PAC-000014 | Marcela: autorregistro con **correo sin verificar** |
| PAC-000015 | Julio: paciente **INACTIVO** con una cita atendida en su historial |
| PAC-000016/17 | Roxana (cuenta, responsable legal) y su hijo Mateo (7): **CM-000031 la reserva ella desde su cuenta** (CU15 A1) |

**Agenda:** 31 horarios (30 presenciales, 1 virtual) sin cruces; 4 bloqueos relativos (feriado pasado, feriado próximo, mantenimiento del gabinete OD-2, vacaciones del Dr. Montaño); fichas de las semanas −4 a +2.

**Citas demo (31):** ATENDIDA 11, PROGRAMADA 13, INASISTENCIA 3, CANCELADA 2, CONFIRMADA 2. Incluyen ficha liberada y reutilizada (CM-008 → CM-009), una reprogramación (CM-003) y el máximo de dos (CM-020), reasignación con `REEMPLAZA` (CM-013 → CM-014), excepción de edad autorizada por administrador (CM-022), cita virtual (CM-010), por voz (CM-025), con seguro, en médica inactiva (CM-011/012) y médico-paciente (CM-015/016).

**Cronología corregida.** CM-008 se registra hace 72 h y se cancela hace 48 h; CM-009 se registra después (hace 30 h). CM-013 se registra hace 72 h y se cancela hace 52 h; su reemplazo CM-014 se registra hace 50 h. Todas las demás se calculan a partir de la ficha (cinco días antes, más los límites de §10).

| Tabla | Mínimo | Demo (acumulado) | Extendido (acumulado) | PUDS-UML |
|---|---:|---:|---:|---:|
| roles | 4 | 4 | 4 | 4 |
| permisos / roles_permisos | 41 / 52 | 41 / 52 | 41 / 52 | 41 / 58 |
| especialidades | 6 | 6 | 6 | 6 |
| administradores / recepcionistas / médicos | 1 / — / — | 2 / 3 / 12 | 2 / 3 / 12 | 2 / 3 / 12 |
| personas | 1 | 38 | 316 | 321 |
| usuarios | 1 | 25 | 78 | 78 |
| pacientes / historias clínicas | — | 17 / 17 | 211 / 211 | 211 / 211 |
| vínculos / seguros | — | 6 / 5 | 90 / 54 | 95 / 54 |
| horarios / bloqueos | — | 31 / 4 | 31 / 4 | 31 / 4 |
| fichas (bloqueadas) | — | 1 548 (96)* | 3 144 (96)* | 2 730 (110) |
| citas / historiales | — | 31 / 55 | 615 / 1 396 | 615 / 1 380 |

\* Dependen del día de ejecución (la simulación se hizo un sábado por la tarde; desde el lunes, la semana en curso aporta fichas futuras). El extendido genera 3 144 fichas por incluir las semanas −8 a +2.

Estados del extendido (con la demo): ATENDIDA 375, CANCELADA 66, CONFIRMADA 31, INASISTENCIA 60, PROGRAMADA 83.

## 10. Coherencia cronológica y reglas

`RegistroCitas::planificar()` valida **antes de escribir** y lanza `SiembraInvalida` si algo no encaja:

- ningún evento en el futuro; historial estrictamente ordenado; confirmar/cancelar antes de la hora (RN21); atendida tras la ficha; inasistencia pasados 15 minutos;
- la cita se registra **después del alta del paciente**, de la verificación de su responsable legal (menores), de su seguro y del correo de la cuenta que reserva por autoservicio;
- edad y sexo de la especialidad en la fecha de la cita (salvo excepción autorizada), seguro propio/verificado/vigente, sin citas cruzadas del paciente, médico y paciente activos en reservas nuevas;
- una ficha reutilizada o un reemplazo solo se registran después de cancelada la cita anterior.

En el extendido, los candidatos que no cumplen se descartan y se prueba otro: **no se fuerzan cantidades**. En la demo, cualquier incumplimiento detiene la siembra.

## 11. Idempotencia

Verificado en la simulación en memoria (con triggers que abortan todo UPDATE/DELETE):

| Escenario | Resultado |
|---|---|
| mínimo repetido | sin errores, ninguna tabla cambia |
| demo repetido al instante | sin errores, ninguna tabla cambia |
| demo un día después (misma semana) | sin errores, ninguna tabla cambia |
| demo ocho días después (otra semana) | sin errores; solo se añaden fichas de las nuevas semanas |
| extendido repetido, +1 día y +8 días | sin errores, 615 citas, sin duplicados; +8 días solo añade fichas |

Mecanismos: catálogos con `insertOrIgnore`; personas por documento o, si no lo tienen, por nombre y **fecha de nacimiento fija** (Sofía; antes variaba cada día y rompía la repetición); perfiles por `persona_id`; horarios por (médico-especialidad, día, hora); bloqueos por (alcance, destino, motivo); fichas por la restricción única; citas por código; historiales solo al crear la cita. La población extendida cuenta las citas ya existentes **por estado** (no por pasado/futuro, que dependería del reloj) y solo crea las que faltan.

Limitaciones: (1) solo se probó con datos en memoria, no en PostgreSQL; (2) los bloqueos y los cierres de vigencia conservan las fechas de la primera ejecución; (3) si una ejecución del extendido termina con menos citas por falta de candidatos, las repeticiones completan solo lo que falta con las fichas libres de ese momento; (4) cada seeder es una transacción, pero la demo completa no lo es: ante un error puede quedar un seeder aplicado y otro no, y basta repetir; (5) no se probó recuperar una ejecución interrumpida a mitad.

## 12. Seguridad

- `Hash::make()` (bcrypt); ninguna contraseña en el código, en la salida ni en la bitácora (la simulación confirmó que todas las claves guardadas son bcrypt).
- `SEED_ADMIN_PASSWORD` y `SEED_DEMO_PASSWORD` en el `.env` local. Política: 12+ caracteres, tres clases de caracteres, sin `vidasalud`, `clinica`, `password`, `12345`, `qwerty`. Sin variable no se crea ninguna cuenta.
- **Producción:** todos los seeders de datos ficticios (`Ciclo1Demo`, `PersonalClinica`, `PacienteDemo`, `Agenda`, `CitaDemo`, `PoblacionExtendida`) se niegan a ejecutarse, incluso con `--class=` (comprobado). `UsuarioInicialSeeder` sí se permite, pero en producción **no usa ningún dato ficticio**: exige las variables `SEED_ADMIN_USUARIO`, `SEED_ADMIN_EMAIL` (no `.test`), `SEED_ADMIN_NOMBRES`, `SEED_ADMIN_APELLIDO_PATERNO`, `SEED_ADMIN_DOCUMENTO`, `SEED_ADMIN_NACIMIENTO`, `SEED_ADMIN_SEXO` y `SEED_ADMIN_TELEFONO`, y aborta nombrando las que falten.
- Los datos de demostración se distinguen solo por convención (`@vidasalud.test`, CI `99xxxxx`), no por una columna.
- `env()` solo funciona sin `config:cache`.
- En PowerShell, `$env:SEED_PERFIL` persiste en la sesión: elimínela al terminar (`Remove-Item Env:SEED_PERFIL`).

## 13. Reglas de negocio RN01–RN25

**Garantizadas por PostgreSQL** (migraciones): RN01 (PK, código y persona únicos), RN02, RN08 (CHECK de que la duración divide el rango), RN09 (único parcial `ux_citas_ficha_activa`), RN11 (triggers de no borrado), RN14, RN23 (EXCLUDE y trigger de rangos; no sembrada).
**Parcialmente cubiertas por PostgreSQL:** RN03 (FK; falta exigir médico activo), RN05 (FK ficha → horario; la rejilla no se valida), RN07 (trigger y EXCLUDE de horarios, y una cita activa por ficha), RN10 (no borrado y consulta finalizada; faltan las transiciones de estado), RN13 (el índice único hace de árbitro; falta la transacción), RN22 (único parcial de documento; no impone «solo un menor sin número»).
**Respetadas únicamente por los datos de los seeders** (validadas por `RegistroCitas` y la simulación; **pendientes de implementar en Laravel**): RN04, RN06 (las citas atendidas, canceladas o con inasistencia son carga histórica), RN15, RN16, RN17, RN18, RN19, RN20, RN21 y RN25.
**Pendientes sin base:** RN12 (permisos por ruta; no hay `fn_tiene_permiso` ni middleware) y RN24 (aviso de valor crítico, Ciclo 2). RN24 ya tiene datos: tres valores críticos sembrados a propósito, con su aviso `VALOR_CRITICO` (§18.2). La lógica que genera el aviso sigue pendiente.

Un conjunto de datos coherente no es una regla implementada: si una futura migración añade disparadores para RN06 o RN21, la carga histórica deberá revisarse.

## 14. Diferencias respecto al PUDS-UML

1. **Nombres físicos:** el documento usa `rol_permiso`, `paciente_seguros`, `historial_citas`, `medico_especialidad`, `pacientes.recepcionista_id`, `citas.usuario_id`, `citas.paciente_seguro_id`; las migraciones (que prevalecen), `roles_permisos`, `seguros_paciente`, `historiales_cita`, `medicos_especialidades`, `recepcionista_registra_id`, `usuario_registra_id`, `seguro_paciente_id`.
2. **Reglas en la base:** el documento las aplica con disparadores (RN04, RN06, RN15–RN21); las migraciones no.
3. **Mecanismo `app.ahora`:** el documento simula un reloj en la base; **no existe** en nuestras migraciones (se comprobó). Las fechas pasadas se cargan como histórico directo.
4. **Contraseña:** el documento fija una común; aquí se sustituye por variables de entorno.
5. **Feriados:** el documento usa 24/09 y 02/11 absolutos; aquí son relativos (si no, colisionarían con las citas según el día de ejecución).
6. **Datos clínicos** (historia clínica, consultas, laboratorio, tratamientos, notificaciones y bitácora): **sí se siembran**, aunque sus pantallas son del Ciclo 2. Son datos coherentes con las citas ya sembradas y con las restricciones de la base (§18). Las 12 tablas que antes quedaban vacías ahora tienen datos en los perfiles demo y extendido. El catálogo de laboratorio va en el perfil mínimo. Para conservar las cantidades del PUDS-UML (615 citas, 375 ATENDIDAS), las 8 citas ATENDIDAS de los casos clínicos de la demo se descuentan del objetivo de la población extendida (356 en vez de 364).
7. **Cantidades:** `roles_permisos` 52 frente a 58; fichas bloqueadas 96 frente a 110; vínculos 90 frente a 95; personas 316 frente a 321.
8. **UML:** 34 clases con `RolPermiso` explícita (las migraciones y `decisiones-implementacion.md` hablan de 33 clases + tabla intermedia: diferencia de representación, no estructural). Comentarios a corregir si usted lo autoriza: migración `100004` y `docs/decisiones-implementacion.md`.
9. **`citas.origen`:** texto libre en la migración; se usan `RECEPCION` y `AUTOSERVICIO`.

## 15. Casos de uso respaldados por los datos

| CU | Datos |
|---|---|
| CU01 Iniciar sesión | 25 cuentas con rol, bcrypt y estado; cuenta inactiva (Dra. Camacho); cuenta **bloqueada** (Gabriel) |
| CU02 Cerrar sesión | sin datos propios |
| CU03 Cambiar contraseña | `admin` con `debe_cambiar_password` (si se define `SEED_ADMIN_PASSWORD`) |
| CU04 Usuarios | cuentas de los cuatro roles, una inactiva, una bloqueada |
| CU05 Roles y permisos | 4 roles, 41 permisos, 52 asignaciones; baja lógica de un rol mediante prueba aislada (§6b) |
| CU06 Especialidades | 6 con reglas; baja lógica mediante prueba aislada (§6b) |
| CU07 Médicos | 12, dos con doble especialidad, uno inactivo |
| CU08 Consultorios | 8 |
| CU09 Horarios | 31 horarios; fichas con bloqueos; un cierre de vigencia |
| CU10 Pacientes | 17 con historia clínica; menores, adultos mayores y un paciente **inactivo con historial** |
| CU11 Buscar paciente | CI, complemento, certificado y sin documento |
| CU12 Autorregistro | Luciana, Valeria, Camila y Marcela (**correo sin verificar**) |
| CU13 Vínculos | responsables y contactos, verificados y sin verificar |
| CU14 Disponibilidad | fichas libres, ocupadas y bloqueadas en 7 semanas |
| CU15 Programar | por recepción, autoservicio, voz, virtual, seguro, excepción y **responsable con cuenta que reserva para un menor** |
| CU16 Reprogramar | CM-003, CM-020 (máximo) y reasignación CM-013 → CM-014 |
| CU17 Cancelar | CM-008, CM-013; ficha reutilizada por CM-009 |
| CU18 Asistencia | ATENDIDA, INASISTENCIA por el sistema, CONFIRMADA |
| CU19 Agenda médica | miércoles de la semana +1 del Dr. Pérez y las agendas de los médicos activos |
| CU20 Historial | historial completo de Fernanda, Carlos, Pedro y el Dr. Pérez |

`notificaciones` y `bitacoras`, que CU15 y CU17 mencionan en sus postcondiciones, ya tienen datos derivados de las citas y de su historial (§18).

## 16. Riesgos y decisiones pendientes

1. Aprobar el catálogo derivado de permisos y las cuatro claves de configuración propuestas.
2. Confirmar con la docente las restricciones de Odontología, Cardiología y Ginecología y las duraciones.
3. **Zona horaria global** de la aplicación (§6).
4. Los servicios del Ciclo 1 deben implementar RN04, RN06, RN15–RN21 y RN25 (§13).
5. El bloqueo de reserva en línea de Carlos (RN19) es una ventana móvil de 6 meses: caduca.
6. Las fechas dependen del día de ejecución: conviene sembrar el mismo día o la víspera de una defensa.
7. Pendiente de verificar en PostgreSQL: todas las inserciones contra CHECK, únicos, EXCLUDE y triggers; los conteos de §9; los tiempos de ejecución (la simulación del extendido tardó ≈2 s en memoria).
8. Faker es dependencia de desarrollo: el perfil extendido no funciona con `composer install --no-dev`.
9. Sin modelos Eloquent aún.

## 17. Instrucciones de ejecución futura (no ejecutadas)

1. **Siempre en `clinica_vida_salud_verificacion` primero**, comprobando `SELECT current_database()` y sin configuración cacheada.
2. En su `.env` local (no se versiona): `SEED_ADMIN_PASSWORD` y `SEED_DEMO_PASSWORD`.
3. PowerShell:

```powershell
php artisan db:seed                                              # mínimo
$env:SEED_PERFIL = 'demo'; php artisan db:seed                   # demo del Ciclo 1
$env:SEED_PERFIL = 'extendido'; php artisan db:seed              # solo cuando se pida
Remove-Item Env:SEED_PERFIL                                      # no dejarlo en la sesión
php artisan db:seed --class=CatalogosSeeder                      # un seeder suelto
```

Nunca `migrate:fresh` ni `db:wipe`. Los seeders no tienen reversa: para repetir una prueba se reconstruye la base de verificación.

## 18. Datos clínicos (Ciclo 2)

Desde noviembre de 2026 los seeders también llenan las 12 tablas clínicas que antes quedaban vacías. Las pantallas del Ciclo 2 ya tienen datos coherentes para mostrar.

### 18.1 Seeders nuevos

| Seeder | Perfil | Qué hace |
|---|---|---|
| `LaboratorioCatalogoSeeder` | mínimo | 8 exámenes (hemograma, glucosa, perfil lipídico, examen general de orina, creatinina, TSH, HbA1c, prueba de embarazo), 19 parámetros y 41 rangos de referencia por edad en **meses** y por sexo (`NULL` = ambos). La hemoglobina tiene rangos distintos para lactante, niño, adolescente, adulto hombre, adulta mujer y adulto mayor. La glucosa del adulto tiene una versión anterior cerrada (2023–2024) para demostrar la elección del rango por vigencia. |
| `ClinicaDemoSeeder` | demo | Antecedentes y alergias de los 17 pacientes demo, 8 citas ATENDIDAS nuevas (CM-000032 a CM-000039) y una consulta FINALIZADA para cada una de las 19 citas ATENDIDAS de la demo, con sus órdenes, resultados y tratamientos. Contiene los casos de §18.2. |
| `ClinicaExtendidaSeeder` | extendido | Antecedentes en ~50 % de los pacientes PAC-000018 a 211 (85 % en adultos mayores) y alergias en ~20 %. Consulta para ~70 % de las citas ATENDIDAS de la población extendida, con diagnóstico CIE-10 según la especialidad y el motivo, signos vitales según la edad, tratamiento cuando el diagnóstico lo pide y órdenes de laboratorio. Los pacientes de los casos guiados quedan fuera para que una consulta al azar no contradiga su evolución. |
| `NotificacionSeeder` | demo y extendido | Avisos derivados del historial de cada cita, de los resultados y de los autorregistros (§18.3). |
| `BitacoraSeeder` | demo y extendido | Registros de solo inserción derivados de todo lo anterior (§18.3). |

Apoyo: `Support/RegistroClinico` valida e inserta consulta, orden con resultados y tratamiento. `Support/CatalogoClinico` reúne los diagnósticos por especialidad y motivo, los tratamientos con sus alternativas para alérgicos, los signos vitales por edad y la generación de valores a partir del rango. También se cambiaron `PacienteDemoSeeder` y `PoblacionExtendidaSeeder` para que algunas cuentas activen las notificaciones push y reciban los recordatorios por `PUSH`.

### 18.2 Casos de demostración

| Caso | Paciente | Recorrido |
|---|---|---|
| **Niño con hemoglobina baja** (pedido por la docente) | Pedro, PAC-000001, 8 años, con responsable legal verificado; Pediatría | CM-000032: Hb 9,6 g/dL **BAJO** → CM-000033: se indica sulfato ferroso (`tratamientos.resultado_laboratorio_id` = ese resultado) → CM-000034: Hb 10,6 **BAJO** → CM-000001: Hb 11,9 **NORMAL**. El tratamiento queda **FINALIZADO** con respuesta **MEJORO**. Cada control es una cita ATENDIDA con su consulta y su orden. |
| **Adulto con glucosa alta** | Andrés, PAC-000006, 45 años; Medicina General | Glucosa en ayunas 182 mg/dL y HbA1c 7,9 % (**ALTO**) → metformina → 131 (**ALTO**) → 96 mg/dL (**NORMAL**). El tratamiento es crónico: sigue EN_CURSO con respuesta MEJORO. |
| **Valor crítico 1** | Fernanda, PAC-000008, 68 años; Geriatría | Hemoglobina 6,6 g/dL **CRITICO_BAJO** (orden urgente). Se deriva a emergencias y se suspende el ácido acetilsalicílico. |
| **Valor crítico 2** | Carlos, PAC-000004, 33 años en la fecha del resultado (34 hoy); Medicina General | Glucosa 486 mg/dL **CRITICO_ALTO** (debut diabético). Hospitalización de 48 h; tratamiento FINALIZADO con respuesta MEJORO. |
| **Valor crítico 3** | Ricardo, PAC-000011, 52 años; Cardiología | Creatinina 4,6 mg/dL **CRITICO_ALTO** (el catálogo no tiene potasio). Se suspende el losartán y se deriva a nefrología. |
| **Alergia a la penicilina** | Luciana, PAC-000005, alergia GRAVE (anafilaxia) | Su amigdalitis (CM-000011) se trata con **azitromicina**, sin ninguna penicilina. Mario (alérgico al ibuprofeno) recibe paracetamol y se le omite el diclofenaco de la pauta habitual. |

Cada valor crítico genera un aviso `VALOR_CRITICO` al médico solicitante por `SISTEMA` y por `CORREO`, un registro `CARGAR_RESULTADOS` en la bitácora con el número de críticos y un tratamiento vinculado al resultado. La población extendida puede producir además algún crítico al azar (en la última ejecución, 2).

### 18.3 Reglas que cumplen los datos

`RegistroClinico` las valida **antes de escribir**. Un incumplimiento lanza `SiembraInvalida` y la transacción completa se revierte.

- **Consultas:** solo para citas ATENDIDAS, una por cita (`cita_id` único) y con inicio y fin dentro del horario de la ficha. Se insertan ya FINALIZADAS y completas, porque `tg_consultas_finalizada_inmutable` impide editarlas. Los códigos CIE-10 son reales y el diagnóstico es acorde a la especialidad, la edad y el sexo. No hay consultas EN_CURSO: la agenda no genera fichas pasadas en la semana actual, así que ninguna cita está en curso.
- **Laboratorio:** las órdenes salen de consultas y siguen la cronología solicitud → toma de muestra → resultado → revisión, sin fechas futuras. Solo las órdenes RESULTADO_LISTO o REVISADA llevan resultados, uno por parámetro. Algunas quedan SOLICITADAS, con MUESTRA_TOMADA o ANULADAS.
- **Rango de referencia:** cada resultado numérico guarda el **único** rango vigente en la fecha del resultado para la edad en meses y el sexo del paciente en esa fecha. La interpretación (NORMAL, BAJO, ALTO, CRITICO_BAJO, CRITICO_ALTO) se calcula con ese rango, evaluando primero los límites críticos. Los parámetros de texto no llevan rango y se interpretan NO_APLICA. En una misma banda de edad nunca se mezcla un rango para ambos sexos con otro por sexo, así que solo hay un rango aplicable por paciente.
- **Alergias:** no se prescribe ningún medicamento de la familia de una alergia activa del paciente (`RegistroClinico::FAMILIAS_ALERGIA`: penicilinas, AINE, sulfamidas, metamizol). Si el medicamento habitual está contraindicado, se usa su alternativa o se omite.
- **Historia clínica:** los antecedentes son coherentes con la edad y el sexo (gineco-obstétricos solo en mujeres adultas, crónicos del adulto desde los 30 años). Unos 10 % de los pacientes conservan el grupo sanguíneo DESCONOCIDO.
- **Notificaciones:** se reproduce lo que haría `CitaService`. CITA_PROGRAMADA, CITA_REPROGRAMADA y CITA_CANCELADA van al paciente (o a su responsable legal si es menor) y al médico. El RECORDATORIO_CITA se fija según las `horas_anticipacion` de la cuenta. RESULTADO_LISTO y VALOR_CRITICO van al médico solicitante y VERIFICAR_CORREO a cada autorregistro. Lo programado para el futuro queda PENDIENTE; lo pasado, ENVIADA o LEIDA, con algún envío FALLIDA (`intentos` > 0). Los recordatorios de una cita cancelada o reprogramada antes de enviarse quedan CANCELADA. `referencia_tipo` y `referencia_id` van siempre juntos.
- **Bitácora:** solo inserción. Usa las mismas acciones que la aplicación (INICIO_SESION, LOGIN_FALLIDO con `exito = false`, CAMBIO_PASSWORD, CREAR_PACIENTE, PROGRAMAR/REPROGRAMAR/CANCELAR_CITA, CAMBIO_ESTADO_CITA, FINALIZAR_CONSULTA, CARGAR_RESULTADOS, etc.). El canal es WEB casi siempre, SISTEMA para las inasistencias y VOZ con `transcripcion_voz` para las citas gestionadas por voz. **Nunca** se guarda una contraseña ni su hash.
- **Determinismo e idempotencia:** cada paciente y cada cita usan su propia semilla fija. Todo se reconoce por su clave natural (consulta por cita, orden por código, tratamiento por consulta y descripción, notificación por destinatario, tipo, canal, referencia y momento, bitácora por acción, usuario, registro y momento) y nada existente se modifica.

### 18.4 Tamaño de cada tabla en el perfil extendido

Resultado de `migrate:fresh --seed` del 07/11/2026. Las fichas, los estados de órdenes y notificaciones, la bitácora y los críticos al azar dependen del día de ejecución.

| Tabla | Filas | Tabla | Filas | Tabla | Filas |
|---|---:|---|---:|---|---:|
| administradores | 2 | alergias | 51 | antecedentes | 204 |
| aseguradoras | 3 | bitacoras | 3 728 | bloqueos_agenda | 4 |
| citas | 615 | configuraciones_sistema | 10 | consultas | 274 |
| consultorios | 8 | especialidades | 6 | fichas | 2 651 |
| historiales_cita | 1 407 | historias_clinicas | 211 | horarios_atencion | 31 |
| medicos | 12 | medicos_especialidades | 14 | notificaciones | 2 243 |
| ordenes_laboratorio | 168 | pacientes | 211 | parametros_laboratorio | 19 |
| permisos | 41 | personas | 316 | prescripciones | 166 |
| rangos_referencia | 41 | recepcionistas | 3 | resultados_laboratorio | 408 |
| roles | 4 | roles_permisos | 52 | seguros_paciente | 54 |
| tipos_examen | 8 | tratamientos | 158 | usuarios | 78 |
| vinculos_paciente | 90 | | | | |

Detalle:
- **Citas:** ATENDIDA 375, CANCELADA 66, CONFIRMADA 31, INASISTENCIA 60, PROGRAMADA 83. La demo tiene ahora 39 citas.
- **Consultas:** 274, es decir el 73 % de las atendidas (19 de la demo y 255 de la extendida).
- **Órdenes:** 117 REVISADA, 34 RESULTADO_LISTO, 10 MUESTRA_TOMADA, 4 SOLICITADA y 3 ANULADA.
- **Tratamientos:** 78 FINALIZADO, 74 EN_CURSO y 6 SUSPENDIDO.
- **Notificaciones:** 1 264 LEIDA, 765 ENVIADA, 114 PENDIENTE, 84 CANCELADA y 16 FALLIDA.
- **Bitácora:** 3 605 WEB, 63 VOZ y 60 SISTEMA.

En el perfil demo: 39 citas (19 ATENDIDAS), 19 consultas, 22 órdenes, 51 resultados (3 críticos), 11 tratamientos, 163 notificaciones y 356 registros de bitácora.

### 18.5 Comprobaciones SQL (perfil extendido)

Todas dan 0 tras `migrate:fresh --seed` y tras dos `db:seed` repetidos, que no añaden ninguna fila:

- resultados numéricos cuyo rango no es el único aplicable a la edad en meses, el sexo y la fecha (0 de 353), e interpretaciones que no coinciden con ese rango;
- resultados de texto con rango o con interpretación distinta de NO_APLICA;
- consultas de citas no ATENDIDAS, fuera del horario de la ficha o no finalizadas (0 de 274);
- prescripciones de un medicamento de la familia de una alergia activa del paciente (0 de 166);
- resultados en órdenes sin resultado y órdenes con resultados incompletos;
- antecedentes gineco-obstétricos fuera de mujeres adultas;
- contraseñas (`password`, `contrase`, `$2y$`) en `datos_anteriores` o `datos_nuevos` de la bitácora, y transcripción de voz sin canal VOZ;
- registros de bitácora o resultados con fecha futura.
