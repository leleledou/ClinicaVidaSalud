# Decisiones de implementación — Clínica Vida Salud

Registro de trazabilidad entre el UML V3.1 y el esquema PostgreSQL implementado
con migraciones de Laravel 12. El UML (`docs/uml/ClinicaVidaSalud_Final.mdj`) **no se modifica**: las
adaptaciones técnicas se documentan aquí.

Fuentes y prioridad ante diferencias:

1. UML V3.1 — estructura y relaciones.
2. Enunciado oficial (`docs/requisitos/EXAMEN 2 SISTEMAS1-S2-2026.pdf`) — comportamiento obligatorio.
3. Documento V3 (`docs/requisitos/Diagrama_Clases_v3_Clinica_Vida_Salud.docx`) — tipos, enumeraciones y reglas.
4. Decisiones técnicas aprobadas en la conversación de diseño.

Estado: **migraciones creadas, no ejecutadas** sobre PostgreSQL.

## 1. Nomenclatura y correspondencia clase UML → tabla

Reglas: español, plural, `snake_case`. Se pluraliza el sustantivo núcleo y el complemento queda
en singular; un adjetivo concuerda en plural; las tablas intermedias pluralizan ambas entidades.
Todos los modelos declararán `protected $table` explícito (el pluralizador de Laravel es inglés).
Los nombres de esta tabla son definitivos.

| # | Clase UML | Tabla | Migración |
|---|---|---|---|
| 1 | Rol | `roles` | `2026_10_24_100002` |
| 2 | Permiso | `permisos` | `…100003` |
| 3 | (asociación OTORGA) | `roles_permisos` | `…100004` |
| 4 | Persona | `personas` | `…100005` |
| 5 | Usuario | `usuarios` | `…100006` |
| 6 | Bitacora | `bitacoras` | `…100007` |
| 7 | ConfiguracionSistema | `configuraciones_sistema` | `…100008` |
| 8 | Recepcionista | `recepcionistas` | `…100009` |
| 9 | Administrador | `administradores` | `…100010` |
| 10 | Medico | `medicos` | `…100011` |
| 11 | Paciente | `pacientes` | `…100012` |
| 12 | VinculoPaciente | `vinculos_paciente` | `…100013` |
| 13 | Especialidad | `especialidades` | `…100014` |
| 14 | MedicoEspecialidad | `medicos_especialidades` | `…100015` |
| 15 | Aseguradora | `aseguradoras` | `…100016` |
| 16 | PacienteSeguro | `seguros_paciente` | `…100017` |
| 17 | Consultorio | `consultorios` | `…100018` |
| 18 | HorarioAtencion | `horarios_atencion` | `…100019` |
| 19 | BloqueoAgenda | `bloqueos_agenda` | `…100020` |
| 20 | Ficha | `fichas` | `…100021` |
| 21 | Cita | `citas` | `…100022` |
| 22 | HistorialCita | `historiales_cita` | `…100023` |
| 23 | HistoriaClinica | `historias_clinicas` | `…100024` |
| 24 | Antecedente | `antecedentes` | `…100025` |
| 25 | Alergia | `alergias` | `…100026` |
| 26 | Consulta | `consultas` | `…100027` |
| 27 | TipoExamen | `tipos_examen` | `…100028` |
| 28 | ParametroLaboratorio | `parametros_laboratorio` | `…100029` |
| 29 | RangoReferencia | `rangos_referencia` | `…100030` |
| 30 | OrdenLaboratorio | `ordenes_laboratorio` | `…100031` |
| 31 | ResultadoLaboratorio | `resultados_laboratorio` | `…100032` |
| 32 | Tratamiento | `tratamientos` | `…100033` |
| 33 | Prescripcion | `prescripciones` | `…100034` |
| 34 | Notificacion | `notificaciones` | `…100035` |

Las 33 clases del UML están representadas; `roles_permisos` es la tabla intermedia de la
asociación muchos a muchos OTORGA (no es una clase). No hay otras tablas de dominio.

### Orden de migración y nombres de archivo

Los archivos usan marcas de tiempo fijas y consecutivas (`2026_10_24_1000NN`) en lugar de las
generadas por `make:migration`, para que el orden de ejecución por dependencias sea
determinista y no dependa del orden alfabético ni del segundo en que se creen.

| Rango | Contenido |
|---|---|
| `0001_01_01_000000` | `password_reset_tokens` y `sessions` de Laravel (ver §8) |
| `0001_01_01_000001/2` | `cache` y `jobs` de Laravel (sin cambios) |
| `100001` | extensión `btree_gist` |
| `100002`–`100035` | las 34 tablas, en el orden de la tabla anterior |
| `100036` | restricciones `EXCLUDE` |
| `100037` | funciones y triggers de integridad |

## 2. Claves foráneas derivadas de las asociaciones

La FK va en el lado `*`. Un extremo `1..1` da FK `NOT NULL`; un extremo `0..1` da FK nullable.
Nombres: `<entidad>_<verbo>_id` para asociaciones de acción (REGISTRA, REALIZA, CARGA, VERIFICA,
AUTORIZA); `<entidad>_id` en las estructurales. Todas son `ON DELETE RESTRICT` salvo `roles_permisos`.

| Asociación UML | Tabla.columna | → Referencia | Nulabilidad |
|---|---|---|---|
| ASIGNADO_A | `usuarios.rol_id` | `roles` | NN |
| OTORGA | `roles_permisos.rol_id`, `.permiso_id` (PK compuesta) | `roles`, `permisos` | NN, CASCADE |
| GENERA (Usuario–Bitacora) | `bitacoras.usuario_id` | `usuarios` | NULL |
| REGISTRA (Usuario–Cita) | `citas.usuario_registra_id` | `usuarios` | NN |
| REALIZA | `historiales_cita.usuario_realiza_id` | `usuarios` | NULL |
| CARGA | `resultados_laboratorio.usuario_carga_id` | `usuarios` | NN |
| ACCEDE_COMO | `usuarios.persona_id` | `personas` | NN, UNIQUE |
| ES_VINCULADA | `vinculos_paciente.persona_id` | `personas` | NN |
| RECIBE | `notificaciones.persona_id` | `personas` | NN |
| ASEGURADO_EN | `seguros_paciente.paciente_id` | `pacientes` | NN |
| SOLICITA (Paciente–Cita) | `citas.paciente_id` | `pacientes` | NN |
| Generalización (×4) | `pacientes`/`medicos`/`recepcionistas`/`administradores`.`persona_id` | `personas` | NN, UNIQUE |
| EJERCE | `medicos_especialidades.medico_id` | `medicos` | NN |
| AUSENCIA_DE | `bloqueos_agenda.medico_id` | `medicos` | NULL |
| /ATIENDE (derivada) | **sin columna** | — | — |
| VERIFICA (Recep.–Vinculo) | `vinculos_paciente.recepcionista_verifica_id` | `recepcionistas` | NULL |
| REGISTRA (Recep.–Paciente) | `pacientes.recepcionista_registra_id` | `recepcionistas` | NULL |
| VERIFICA (Recep.–PacienteSeguro) | `seguros_paciente.recepcionista_verifica_id` | `recepcionistas` | NULL |
| AUTORIZA_EXCEPCION | `citas.administrador_autoriza_id` | `administradores` | NULL |
| TIENE (composición) | `vinculos_paciente.paciente_id` | `pacientes` | NN |
| EJERCIDA_POR | `medicos_especialidades.especialidad_id` | `especialidades` | NN |
| SE_ATIENDE_EN (MedEsp.–Horario) | `horarios_atencion.medico_especialidad_id` | `medicos_especialidades` | NN |
| EMITE | `seguros_paciente.aseguradora_id` | `aseguradoras` | NN |
| CUBRE | `citas.seguro_paciente_id` | `seguros_paciente` | NULL |
| UBICA | `horarios_atencion.consultorio_id` | `consultorios` | NULL |
| CIERRA | `bloqueos_agenda.consultorio_id` | `consultorios` | NULL |
| GENERA (Horario–Ficha) | `fichas.horario_atencion_id` | `horarios_atencion` | NN |
| OCUPADA_POR | `citas.ficha_id` | `fichas` | NN |
| FICHA_ANTERIOR / FICHA_NUEVA | `historiales_cita.ficha_anterior_id` / `.ficha_nueva_id` | `fichas` | NULL |
| REEMPLAZA | `citas.cita_reemplazada_id` | `citas` | NULL, UNIQUE |
| SE_ATIENDE_EN (Cita–Consulta) | `consultas.cita_id` | `citas` | NN, UNIQUE |
| REGISTRA_CAMBIO (composición) | `historiales_cita.cita_id` | `citas` | NN |
| POSEE (composición) | `historias_clinicas.paciente_id` | `pacientes` | NN, UNIQUE |
| REGISTRA (Antecedente) | `antecedentes.historia_clinica_id` | `historias_clinicas` | NN |
| ADVIERTE | `alergias.historia_clinica_id` | `historias_clinicas` | NN |
| SOLICITA (Consulta–Orden) | `ordenes_laboratorio.consulta_id` | `consultas` | NN |
| INDICA (composición) | `tratamientos.consulta_id` | `consultas` | NN |
| PRESCRIBE (composición) | `prescripciones.tratamiento_id` | `tratamientos` | NN |
| PEDIDO_EN | `ordenes_laboratorio.tipo_examen_id` | `tipos_examen` | NN |
| MEDIDO_EN | `resultados_laboratorio.parametro_laboratorio_id` | `parametros_laboratorio` | NN |
| MIDE (composición) | `parametros_laboratorio.tipo_examen_id` | `tipos_examen` | NN |
| APLICADO_A | `resultados_laboratorio.rango_referencia_id` | `rangos_referencia` | NULL |
| SE_EVALUA_CON (composición) | `rangos_referencia.parametro_laboratorio_id` | `parametros_laboratorio` | NN |
| ORIGINA | `tratamientos.resultado_laboratorio_id` | `resultados_laboratorio` | NULL |
| PRODUCE (composición) | `resultados_laboratorio.orden_laboratorio_id` | `ordenes_laboratorio` | NN |

Las 45 asociaciones están cubiertas. `bitacoras.registro_id` y `notificaciones.referencia_id` son
referencias polimórficas del UML: **sin FK**.

## 3. Diferencias entre el UML y PostgreSQL

| Tema | UML | PostgreSQL |
|---|---|---|
| Claves foráneas | no son atributos; se expresan con asociaciones | columnas `*_id` con FK (§2) |
| Asociación derivada `/ATIENDE` | Médico–Cita | sin columna ni tabla; método futuro `Cita::medicoAsignado()` (Cita → Ficha → HorarioAtencion → MedicoEspecialidad → Medico) |
| Atributo derivado `Ficha./ocupada` | derivado | no se almacena; se calcula con la existencia de una cita no cancelada |
| OTORGA (muchos a muchos) | asociación | tabla `roles_permisos` |
| Generalización de Persona `{incompleta, superpuesta}` | herencia | una tabla por subclase con `persona_id` UNIQUE hacia `personas` |
| `MedicoEspecialidad` | clase sin `id` | `id` técnico (§4) |
| Tipos de atributo | sin tipo en el `.mdj` (`type:""`) | tipos del diccionario V3 (§6) |
| Enumeraciones | 23 en V3 | `varchar(30)` + `CHECK` (§6) |
| `Cita.origen` | está en el UML | está en la migración como texto nullable; **no está en V3** (§9, D1) |
| Métodos | ninguno, de forma intencional | no aplica; los de relación se crearán en los modelos |

## 4. ADR-01 — `id` técnico en `medicos_especialidades`

Adaptación de implementación, no un cambio del UML. Eloquent no soporta claves primarias
compuestas y `HorarioAtencion` (SE_ATIENDE_EN) necesita referenciar una sola columna. Se mantiene
`UNIQUE (medico_id, especialidad_id)`, por lo que EJERCE, EJERCIDA_POR y SE_ATIENDE_EN conservan su
significado. Además, una sola especialidad principal activa por médico
(`ux_medicos_especialidades_principal`). El mínimo de una especialidad por médico (`1..*`) lo valida
el servicio de alta del médico.

## 5. Política de eliminación y conservación

- **Todas las FK son `RESTRICT`.** La única cascada es `roles_permisos` (configuración de acceso
  sin historial; roles y permisos tampoco se eliminan físicamente).
- **Baja lógica** con los atributos del UML (`estado`, `vigente`, `activa`, `vigente_hasta`);
  no se agrega `deleted_at` ni `SoftDeletes`.
- **Trigger de bloqueo de `DELETE`** (`fn_bloquear_delete`) en: `bitacoras`, `historiales_cita`,
  `citas`, `consultas`, `historias_clinicas`, `antecedentes`, `alergias`, `tratamientos`,
  `prescripciones`, `ordenes_laboratorio`, `resultados_laboratorio`, `seguros_paciente`,
  `vinculos_paciente`, `pacientes`, `medicos`, `recepcionistas`, `administradores`, `personas`, `usuarios`.
- **Solo inserción** (`fn_bloquear_update`): `bitacoras` e `historiales_cita`.
- `TRUNCATE` no dispara estos triggers; `migrate:fresh` elimina tablas y funciones se recrean con
  `CREATE OR REPLACE`.
- Sin regla de baja definida (decisión de negocio pendiente): `bloqueos_agenda`, `fichas`,
  `horarios_atencion`, `parametros_laboratorio`, `notificaciones` y catálogos no protegidos por trigger.

## 6. Tipos de datos y enumeraciones

Convenciones: `id` `bigint` autoincremental (V3 dice `int`; `bigint` es el estándar de Laravel y
compatible); strings `varchar(255)` salvo códigos/teléfonos/NIT (`30`), `ip` (`45`), complemento y
expedido (`10`), CIE-10 (`10`); textos amplios `text`; fechas `date`; horas `time(0)`; instantes
`timestamp(0)` sin zona (la zona horaria es la de la aplicación, ver §10); `decimal` para magnitudes
clínicas (`peso_kg`, `talla_cm` `(5,2)`; `temperatura_c` `(4,1)`; valores de laboratorio `(12,4)`);
`jsonb` para JSON. Las **longitudes y precisiones son propuestas** (V3 solo da tipos lógicos).
No se usan `created_at`/`updated_at`: el UML trae sus fechas propias (`$timestamps = false` en los modelos).

Las 23 enumeraciones de V3 se implementan como `varchar(30)` + `CHECK` nombrado
(`ck_<tabla>_<columna>`), con los valores originales y sin tablas adicionales. Es la forma más
simple de mantener y verificar desde Laravel (las clases `App\Enums\*` y los casts llegan con los modelos).

| Enumeración | Columna(s) |
|---|---|
| EstadoRegistro | `estado` en roles, usuarios, recepcionistas, administradores, medicos, pacientes, vinculos_paciente, especialidades, medicos_especialidades, aseguradoras, seguros_paciente, consultorios, horarios_atencion, tipos_examen |
| TipoDocumento | `personas.tipo_documento` |
| Sexo | `personas.sexo`, `especialidades.sexo_permitido`, `rangos_referencia.sexo` |
| Turno | `recepcionistas.turno` |
| Canal | `citas.canal` (solo WEB/VOZ), `historiales_cita.canal`, `bitacoras.canal` |
| Modalidad | `horarios_atencion.modalidad` |
| AlcanceBloqueo | `bloqueos_agenda.alcance` |
| EstadoFicha | `fichas.estado` |
| EstadoCita | `citas.estado`, `historiales_cita.estado_anterior/estado_nuevo` |
| GrupoSanguineo | `historias_clinicas.grupo_sanguineo` |
| TipoAntecedente, TipoAlergia, Severidad | `antecedentes.tipo`, `alergias.tipo`, `alergias.severidad` |
| EstadoConsulta | `consultas.estado` |
| EstadoTratamiento, RespuestaTratamiento | `tratamientos.estado`, `tratamientos.respuesta` |
| TipoValor | `parametros_laboratorio.tipo_valor` |
| Prioridad, EstadoOrden | `ordenes_laboratorio.prioridad`, `.estado` |
| Interpretacion | `resultados_laboratorio.interpretacion` |
| TipoNotificacion, CanalNotificacion, EstadoNotificacion | `notificaciones.tipo`, `.canal`, `.estado` |

`configuraciones_sistema.tipo_dato` (`int, string, bool, decimal`) no es una de las 23 enumeraciones
de V3; sus valores salen de la descripción del atributo.

## 7. Restricciones avanzadas

| Restricción | Dónde | Migración |
|---|---|---|
| Documento único `(tipo_documento, numero_documento, COALESCE(complemento,''))` solo si hay número. Un complemento ausente se normaliza a `''` en el índice; las personas sin documento no entran | `ux_personas_documento` | 100005 |
| Nombres únicos normalizados `lower(btrim(nombre))` (roles, permisos, especialidades, aseguradoras, consultorios, tipos de examen; parámetros por tipo de examen) | `ux_*_nombre` | varias |
| Una sola cita no cancelada por ficha | `ux_citas_ficha_activa` (único parcial `WHERE estado <> 'CANCELADA'`) | 100022 |
| `cita_reemplazada_id` único y distinto de `id` | `UNIQUE`, `ck_citas_no_autoreemplazo` | 100022 |
| Reemplazo: cita anterior CANCELADA, mismo paciente, vínculo inmutable | trigger `tg_citas_reemplazo` | 100037 |
| Motivo obligatorio al cancelar; excepción de edad ⇒ administrador + justificación | `ck_citas_cancelacion`, `ck_citas_excepcion` | 100022 |
| BloqueoAgenda por alcance (CLINICA/CONSULTORIO/MEDICO) y `fin > inicio` | `ck_bloqueos_alcance_destino`, `ck_bloqueos_rango` | 100020 |
| Horario: día 1–7, `hora_fin > hora_inicio`, duración > 0 y divide el intervalo (RN08), vigencia coherente, PRESENCIAL ⇔ consultorio | `ck_horarios_*` | 100019 |
| Consultorio no usado dos veces (mismo día, franja y vigencia solapadas) | `ex_horarios_consultorio` (EXCLUDE gist) | 100036 |
| Médico sin horarios activos cruzados (aunque sean de otra especialidad) | trigger `tg_horarios_solape_medico` con `pg_advisory_xact_lock` | 100037 |
| Fichas no duplicadas por horario/fecha/hora; `hora_fin > hora_inicio` | `ux_fichas_horario_fecha_hora`, `ck_fichas_horas` | 100021 |
| Una historia clínica por paciente; una consulta por cita | `UNIQUE` en `historias_clinicas.paciente_id`, `consultas.cita_id` | 100024/27 |
| Un resultado por parámetro y orden | `ux_resultados_orden_parametro` | 100032 |
| Signos vitales y rangos coherentes | `ck_consultas_signos_vitales`, `ck_rangos_valores`, `ck_rangos_edades` | 100027/30 |
| Rangos de referencia sin solape (parámetro, sexo, edad, vigencia) | `ex_rangos_referencia_solape` (EXCLUDE gist) | 100036 |
| Rango usado no se edita (solo cerrar vigencia) | trigger `tg_rangos_referencia_usado` | 100037 |
| Consulta FINALIZADA inmutable (RN10) | trigger `tg_consultas_finalizada_inmutable` | 100037 |
| Bitácora y historial de citas solo de inserción; sin `DELETE` físico | triggers (§5) | 100037 |

### Reservas simultáneas (para la etapa de servicios)

El índice `ux_citas_ficha_activa` es el árbitro final. El servicio debe reservar dentro de
`DB::transaction()` con `Ficha::whereKey($id)->lockForUpdate()`, validar disponibilidad y bloqueos,
crear la cita y capturar `UniqueConstraintViolationException` (SQLSTATE `23505`). Al reprogramar o
reemplazar, bloquear las fichas en orden ascendente de `id` para evitar interbloqueos. `Ficha.estado`
(HABILITADA/BLOQUEADA) no sustituye al atributo derivado `/ocupada`.

## 8. Cambios sobre las migraciones originales de Laravel

- `0001_01_01_000000_create_users_table.php` → **renombrada** (`git mv`) a
  `0001_01_01_000000_create_auth_support_tables.php`. Se retiró la tabla `users`; se conservan
  `password_reset_tokens` y `sessions`. Aún no se había ejecutado (la tabla `migrations` no existía).
- `0001_01_01_000001_create_cache_table.php` y `…000002_create_jobs_table.php`: sin cambios.

Adaptaciones pendientes de la etapa de autenticación (no hechas):
`App\Models\User` → `Usuario` con `$table = 'usuarios'`; `config/auth.php` (`providers.users.model`);
autenticación por `correo`/`nombre_usuario`; `getEmailForPasswordReset()` → `correo`; sin
`remember_token` (no está en el UML, `getRememberTokenName()` devuelve `''`); `UserFactory` →
`UsuarioFactory`; `DatabaseSeeder` sin `User::factory()`.

## 9. Discrepancias UML ↔ V3 ↔ enunciado y decisiones pendientes

| # | Hallazgo | Tratamiento |
|---|---|---|
| D1 | `Cita.origen` está en el UML y no en V3 (V3 eliminó `OrigenRegistro`) | columna `varchar(30)` nullable, sin catálogo; pendiente de definición |
| D2 | V3 lista métodos; el UML no los tiene (intencional) | no se agregan al UML |
| D3 | V3 §10.3: la consulta "solo existe para una cita ATENDIDA", pero admite consultas EN_CURSO | **sin restricción en BD**; decidirá el servicio |
| D4 | formato de códigos: enunciado `CM-0001`, V3 `CM-000001` | `varchar(30)` UNIQUE; formato lo genera la aplicación |
| D5 | unicidad del documento con `complemento` | índice con `COALESCE` (§7); se desvía de la letra de V3 |
| D6 | `tipo_dato` de ConfiguracionSistema no es una de las 23 enumeraciones | `CHECK` propio |
| D8 | `Cita.canal` solo WEB/VOZ (SISTEMA aplica a historial y bitácora) | `ck_citas_canal` |
| D9 | reglas de no solape entre tablas | consultorio: EXCLUDE; médico: trigger; paciente: pendiente (abajo) |

Reglas de negocio **no implementadas en la base de datos** (quedan para servicios, no omitidas):
paciente sin dos citas cruzadas; seguro del mismo paciente, verificado y vigente en la fecha de la
cita; transiciones de estado de la cita y cuándo se puede confirmar/cancelar; máximo de
reprogramaciones (se cuenta en `historiales_cita`); edad y sexo por especialidad; paciente con varias
inasistencias; acceso al cardex; alergia activa vs. medicamento; mínimo de especialidad por médico y de
parámetro por examen; qué hacer con fichas al crear un bloqueo; valores de `Cita.origen`.

Ciclos: el enunciado solo fija fechas (Presentación 1: 25/10/2026, avance ≥ 50 % del Ciclo 1;
Presentación 2: 08/11/2026) y no reparte los RF entre ciclos. El esquema completo sirve a ambos.

## 10. Verificación y pasos siguientes

- Hecho: sintaxis PHP de las 40 migraciones; 34 tablas esperadas, ninguna faltante ni extra; las FK
  apuntan a tablas creadas antes; `down()` coherente; sin marcas de tiempo duplicadas.
- **No hecho:** las sentencias SQL (`CHECK`, índices parciales, `EXCLUDE`, triggers) no se han
  ejecutado contra PostgreSQL. Verificarlas primero en una base descartable.
- `APP_TIMEZONE` está en `UTC` (`config/app.php`); como las columnas son `timestamp` sin zona, definir
  `America/La_Paz` antes de guardar datos.
- La extensión `btree_gist` requiere permiso del rol de la base (extensión "trusted" desde PostgreSQL 13).
