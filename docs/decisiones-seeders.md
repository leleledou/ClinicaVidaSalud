# Decisiones de los seeders — Clínica Vida Salud (Ciclo 1)

## Estado de verificación (leer primero)

Los seeders **todavía no se han ejecutado en PostgreSQL**. Lo verificado hasta ahora:

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
| `CatalogosSeeder` | — | orquesta los cinco siguientes |
| `SeguridadSeeder` | `roles`, `permisos`, `roles_permisos` | agrupados: la intermedia exige ambos catálogos |
| `ConfiguracionSistemaSeeder` | `configuraciones_sistema` | `insertOrIgnore`: no pisa valores ya cambiados |
| `EspecialidadSeeder`, `AseguradoraSeeder`, `ConsultorioSeeder` | sus tablas | |
| `UsuarioInicialSeeder` | `personas`, `administradores`, `usuarios` | único seeder de cuentas permitido en producción (ver §12) |
| `Ciclo1DemoSeeder` | — | orquesta los cuatro siguientes |
| `PersonalClinicaSeeder` | personas, administradores, recepcionistas, médicos, `medicos_especialidades`, usuarios | |
| `PacienteDemoSeeder` | personas, pacientes, `historias_clinicas`, `vinculos_paciente`, `seguros_paciente`, usuarios | `historias_clinicas` se incluye porque CU10 la abre al registrar |
| `AgendaSeeder` | `horarios_atencion`, `bloqueos_agenda`, `fichas` | |
| `CitaDemoSeeder` | `citas`, `historiales_cita` | |
| `PoblacionExtendidaSeeder` | las anteriores | **opcional**; nunca desde el perfil mínimo |
| `DatabaseSeeder` | — | elige el perfil con `SEED_PERFIL` |

Apoyo en `database/seeders/Support/`: `Siembra` (reloj y zona, búsqueda e inserción idempotente, contraseñas, guarda de producción), `AgendaFichas`, `RegistroCitas` (cita + historial, con validación previa) y `SiembraInvalida`.

El historial de una cita se inserta junto con ella porque `historiales_cita` es de solo inserción.

## 4. Orden de ejecución

- **mínimo:** Seguridad → Configuración → Especialidad → Aseguradora → Consultorio → Usuario inicial.
- **demo:** + Personal → Pacientes → Agenda (horarios → bloqueos → fichas) → Citas.
- **extendido:** + pacientes → fichas de las semanas −8 a +2 → citas.

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
**Pendientes sin datos ni base:** RN12 (permisos por ruta; no hay `fn_tiene_permiso` ni middleware) y RN24 (aviso de valor crítico, Ciclo 2).

Un conjunto de datos coherente no es una regla implementada: si una futura migración añade disparadores para RN06 o RN21, la carga histórica deberá revisarse.

## 14. Diferencias respecto al PUDS-UML

1. **Nombres físicos:** el documento usa `rol_permiso`, `paciente_seguros`, `historial_citas`, `medico_especialidad`, `pacientes.recepcionista_id`, `citas.usuario_id`, `citas.paciente_seguro_id`; las migraciones (que prevalecen), `roles_permisos`, `seguros_paciente`, `historiales_cita`, `medicos_especialidades`, `recepcionista_registra_id`, `usuario_registra_id`, `seguro_paciente_id`.
2. **Reglas en la base:** el documento las aplica con disparadores (RN04, RN06, RN15–RN21); las migraciones no.
3. **Mecanismo `app.ahora`:** el documento simula un reloj en la base; **no existe** en nuestras migraciones (se comprobó). Las fechas pasadas se cargan como histórico directo.
4. **Contraseña:** el documento fija una común; aquí se sustituye por variables de entorno.
5. **Feriados:** el documento usa 24/09 y 02/11 absolutos; aquí son relativos (si no, colisionarían con las citas según el día de ejecución).
6. **Datos clínicos** (consultas, laboratorio, notificaciones, bitácoras): no se siembran; son del Ciclo 2.
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

Sin datos todavía (ver §16): `notificaciones` y `bitacoras`, que CU15 y CU17 mencionan en sus postcondiciones.

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
