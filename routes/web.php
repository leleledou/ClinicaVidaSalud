<?php

use App\Http\Controllers\LoginController;
use App\Http\Controllers\PanelController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\PersonaController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\BitacoraController;
use App\Http\Controllers\ConfiguracionSistemaController;
use App\Http\Controllers\RecepcionistaController;
use App\Http\Controllers\AdministradorController;
use App\Http\Controllers\MedicoController;
use App\Http\Controllers\PacienteController;
use App\Http\Controllers\VinculoPacienteController;
use App\Http\Controllers\EspecialidadController;
use App\Http\Controllers\MedicoEspecialidadController;
use App\Http\Controllers\AseguradoraController;
use App\Http\Controllers\PacienteSeguroController;
use App\Http\Controllers\PerfilPacienteController;
use App\Http\Controllers\ConsultorioController;
use App\Http\Controllers\HorarioAtencionController;
use App\Http\Controllers\BloqueoAgendaController;
use App\Http\Controllers\FichaController;
use App\Http\Controllers\CitaController;
use App\Http\Controllers\HistorialCitaController;
use App\Http\Controllers\HistoriaClinicaController;
use App\Http\Controllers\AntecedenteController;
use App\Http\Controllers\AlergiaController;
use App\Http\Controllers\ConsultaController;
use App\Http\Controllers\TipoExamenController;
use App\Http\Controllers\ParametroLaboratorioController;
use App\Http\Controllers\RangoReferenciaController;
use App\Http\Controllers\OrdenLaboratorioController;
use App\Http\Controllers\ResultadoLaboratorioController;
use App\Http\Controllers\TratamientoController;
use App\Http\Controllers\PrescripcionController;
use App\Http\Controllers\NotificacionController;
use Illuminate\Support\Facades\Route;

// CU01 Iniciar sesión: solo para quien no tiene sesión.
Route::middleware("guest")->group(function(){
    Route::prefix("login")->controller(LoginController::class)->group(function(){
        Route::get('/','mostrar')->name("login");
        Route::post('/','ingresar')->name("login.ingresar");
    });

    // CU12 Autorregistrarse como paciente (solo sin sesión). throttle: limita los envíos repetidos.
    Route::prefix("registro")->controller(RegistroController::class)->group(function(){
        Route::get('/','create')->name("registro");
        Route::post('/','store')->middleware("throttle:10,1")->name("registro.guardar");
    });
});

// CU12 paso 8 y A2: verificar el correo y reenviar el enlace funcionan con o sin sesión (el
// paciente que ya inició sesión llega aquí desde el aviso del panel). El enlace de verificación
// es una URL firmada temporal: la firma se revisa en el controlador para mostrar el mensaje en español.
Route::prefix("registro")->controller(RegistroController::class)->group(function(){
    Route::get('/verificar/{id}/{hash}','verificar')->name("registro.verificar");
    Route::get('/reenviar','formularioReenviar')->name("registro.reenviar");
    Route::post('/reenviar','reenviar')->middleware("throttle:5,1")->name("registro.reenviar.enviar");
});

// CU02 y CU03: con sesión, pero SIN "cambio.password" (quien tiene contraseña
// temporal debe poder cambiarla y salir).
Route::middleware(["auth", "auth.session", "cuenta.activa", "inactividad"])->group(function(){
    // CU02 Cerrar sesión.
    Route::prefix("logout")->controller(LoginController::class)->group(function(){
        Route::post('/','salir')->name("logout");
    });

    // CU03 Cambiar contraseña.
    Route::prefix("cuenta")->controller(PasswordController::class)->group(function(){
        Route::get('/password','edit')->name("password.editar");
        Route::post('/password','update')->name("password.actualizar");
    });
});

// Rutas internas: sin sesión redirigen a /login (prueba mínima 9).
Route::middleware(["auth", "auth.session", "cuenta.activa", "inactividad", "cambio.password"])->group(function(){
    // La entrada lleva al panel.
    Route::get('/', function () {
        return redirect()->route("panel");
    });

    // CU01 paso 8: panel.
    Route::prefix("panel")->controller(PanelController::class)->group(function(){
        Route::get('/','index')->name("panel");
    });

    // CU05 Gestionar roles y permisos (RN12). Los permisos del rol se guardan con update
    // (roles_permisos con sync). delete = desactivar (RN11).
    Route::prefix("roles")->middleware("can:roles.gestionar")->controller(RolController::class)->group(function(){
        Route::get('/','index')->name("roles.listar");
        Route::post('/','store')->name("roles.guardar");
        Route::post('/update','update')->name("roles.modificar");
        Route::post('/delete','delete')->name("roles.eliminar");
        Route::post('/activar','activar')->name("roles.activar");
        Route::get('/{id}','show')->name("roles.mostrar");
    });

    Route::prefix("permisos")->controller(PermisoController::class)->group(function(){
        Route::get('/','index')->name("permisos.listar");
        Route::post('/','store')->name("permisos.guardar");
        Route::post('/update','update')->name("permisos.modificar");
        Route::post('/delete','delete')->name("permisos.eliminar");
        Route::get('/{id}','show')->name("permisos.mostrar");
    });

    Route::prefix("personas")->controller(PersonaController::class)->group(function(){
        Route::get('/','index')->name("personas.listar");
        Route::post('/','store')->name("personas.guardar");
        Route::post('/update','update')->name("personas.modificar");
        Route::post('/delete','delete')->name("personas.eliminar");
        // CU07 paso 4 / CU10 paso 2: buscar persona por documento (JSON). Va antes de /{id}
        // para que «buscar» no se tome como un id. El permiso se revisa en el controlador.
        Route::get('/buscar','buscar')->name("personas.buscar");
        Route::get('/{id}','show')->name("personas.mostrar");
    });

    // CU04 Gestionar usuarios. delete = desactivar (RN11).
    Route::prefix("usuarios")->middleware("can:usuarios.gestionar")->controller(UsuarioController::class)->group(function(){
        Route::get('/','index')->name("usuarios.listar");
        Route::post('/','store')->name("usuarios.guardar");
        Route::post('/update','update')->name("usuarios.modificar");
        Route::post('/delete','delete')->name("usuarios.eliminar");
        Route::post('/activar','activar')->name("usuarios.activar");
        Route::post('/desbloquear','desbloquear')->name("usuarios.desbloquear");
        Route::post('/restablecer','restablecer')->name("usuarios.restablecer");
        Route::get('/{id}','show')->name("usuarios.mostrar");
    });

    // CU21 Consultar bitácora (RF-06). Solo lectura: bitacoras es de solo inserción.
    Route::prefix("bitacora")->middleware("can:bitacora.consultar")->controller(BitacoraController::class)->group(function(){
        Route::get('/','index')->name("bitacora.listar");
        Route::get('/{id}','show')->whereNumber('id')->name("bitacora.mostrar");
    });

    // CU22 Gestionar configuración del sistema (RF-07). Solo se modifica el valor: las claves las usa el código.
    Route::prefix("configuraciones")->middleware("can:configuracion.gestionar")->controller(ConfiguracionSistemaController::class)->group(function(){
        Route::get('/','index')->name("configuraciones.listar");
        Route::post('/update','update')->name("configuraciones.modificar");
        Route::get('/{clave}','show')->name("configuraciones.mostrar");
    });

    // CU07 Gestionar médicos (RF-09). delete = desactivar (RN11).
    Route::prefix("medicos")->middleware("can:medicos.gestionar")->controller(MedicoController::class)->group(function(){
        Route::get('/','index')->name("medicos.listar");
        Route::post('/','store')->name("medicos.guardar");
        Route::post('/update','update')->name("medicos.modificar");
        Route::post('/delete','delete')->name("medicos.eliminar");
        Route::post('/activar','activar')->name("medicos.activar");
        Route::get('/{id}','show')->name("medicos.mostrar");
    });

    // CU11 Buscar paciente (pacientes.buscar) y CU10 Gestionar pacientes (pacientes.gestionar). delete = desactivar (RN11).
    Route::prefix("pacientes")->controller(PacienteController::class)->group(function(){
        Route::get('/','index')->middleware("can:pacientes.buscar")->name("pacientes.listar");
        Route::post('/','store')->middleware("can:pacientes.gestionar")->name("pacientes.guardar");
        Route::post('/update','update')->middleware("can:pacientes.gestionar")->name("pacientes.modificar");
        Route::post('/delete','delete')->middleware("can:pacientes.gestionar")->name("pacientes.eliminar");
        Route::post('/activar','activar')->middleware("can:pacientes.gestionar")->name("pacientes.activar");
        Route::get('/{id}','show')->middleware("can:pacientes.buscar")->name("pacientes.mostrar");
    });

    // CU13 Gestionar vínculos del paciente. La autorización se revisa en el controlador (recepción con
    // vinculos.gestionar / vinculos.verificar, o el propio paciente, A1). delete = desactivar (A2).
    Route::prefix("vinculos-paciente")->controller(VinculoPacienteController::class)->group(function(){
        Route::get('/','index')->name("vinculos-paciente.listar");
        Route::post('/','store')->name("vinculos-paciente.guardar");
        Route::post('/update','update')->name("vinculos-paciente.modificar");
        Route::post('/delete','delete')->name("vinculos-paciente.eliminar");
        Route::post('/verificar','verificar')->name("vinculos-paciente.verificar");
    });

    // CU06 Gestionar especialidades (RF-08). delete = desactivar (RN11).
    Route::prefix("especialidades")->middleware("can:especialidades.gestionar")->controller(EspecialidadController::class)->group(function(){
        Route::get('/','index')->name("especialidades.listar");
        Route::post('/','store')->name("especialidades.guardar");
        Route::post('/update','update')->name("especialidades.modificar");
        Route::post('/delete','delete')->name("especialidades.eliminar");
        Route::post('/activar','activar')->name("especialidades.activar");
        Route::get('/{id}','show')->name("especialidades.mostrar");
    });

    // CU23 Gestionar aseguradoras (RF-13). delete = desactivar (RN11).
    Route::prefix("aseguradoras")->middleware("can:aseguradoras.gestionar")->controller(AseguradoraController::class)->group(function(){
        Route::get('/','index')->name("aseguradoras.listar");
        Route::post('/','store')->name("aseguradoras.guardar");
        Route::post('/update','update')->name("aseguradoras.modificar");
        Route::post('/delete','delete')->name("aseguradoras.eliminar");
        Route::post('/activar','activar')->name("aseguradoras.activar");
        Route::get('/{id}','show')->whereNumber('id')->name("aseguradoras.mostrar");
    });

    // CU24 Gestionar seguros del paciente (RF-18). Página por paciente (?paciente_id=); los permisos
    // (seguros.gestionar / seguros.verificar) se revisan en el controlador. delete = desactivar (RN11).
    Route::prefix("seguros-paciente")->controller(PacienteSeguroController::class)->group(function(){
        Route::get('/','index')->name("seguros-paciente.listar");
        Route::post('/','store')->name("seguros-paciente.guardar");
        Route::post('/update','update')->name("seguros-paciente.modificar");
        Route::post('/delete','delete')->name("seguros-paciente.eliminar");
        Route::post('/activar','activar')->name("seguros-paciente.activar");
        Route::post('/verificar','verificar')->name("seguros-paciente.verificar");
    });

    // CU28 Gestionar perfil del paciente (RF-19): solo el propio perfil (no recibe id).
    Route::prefix("perfil")->middleware("can:perfil.gestionar")->controller(PerfilPacienteController::class)->group(function(){
        Route::get('/','show')->name("perfil.mostrar");
        Route::post('/update','update')->name("perfil.modificar");
    });

    // CU08 Gestionar consultorios. delete = desactivar (RN11).
    Route::prefix("consultorios")->middleware("can:consultorios.gestionar")->controller(ConsultorioController::class)->group(function(){
        Route::get('/','index')->name("consultorios.listar");
        Route::post('/','store')->name("consultorios.guardar");
        Route::post('/update','update')->name("consultorios.modificar");
        Route::post('/delete','delete')->name("consultorios.eliminar");
        Route::post('/activar','activar')->name("consultorios.activar");
        Route::get('/{id}','show')->name("consultorios.mostrar");
    });

    // CU09 Gestionar horarios de atención (RF-11). Sin update: un horario no se edita, se cierra
    // su vigencia (delete, A1) y se registra uno nuevo. generar = más semanas de fichas (A2).
    Route::prefix("horarios")->middleware("can:horarios.gestionar")->controller(HorarioAtencionController::class)->group(function(){
        Route::get('/','index')->name("horarios.listar");
        Route::post('/','store')->name("horarios.guardar");
        Route::post('/delete','delete')->name("horarios.eliminar");
        Route::post('/generar','generarFichas')->name("horarios.generar");
        Route::get('/{id}','show')->name("horarios.mostrar");
    });

    // CU25 Gestionar bloqueos de agenda (RF-12). update = solo el motivo; delete = levantar el bloqueo.
    Route::prefix("bloqueos")->middleware("can:bloqueos.gestionar")->controller(BloqueoAgendaController::class)->group(function(){
        Route::get('/','index')->name("bloqueos.listar");
        Route::post('/','store')->name("bloqueos.guardar");
        Route::post('/update','update')->name("bloqueos.modificar");
        Route::post('/delete','delete')->name("bloqueos.eliminar");
        Route::get('/{id}','show')->whereNumber('id')->name("bloqueos.mostrar");
    });

    // CU14 Consultar disponibilidad de fichas (paciente y recepcionista).
    // Solo consulta: las fichas se generan en CU09.
    Route::prefix("fichas")->middleware("can:fichas.consultar")->controller(FichaController::class)->group(function(){
        Route::get('/','index')->name("fichas.disponibilidad");
    });

    // CU15 Programar cita (citas.programar = recepción; citas.autoservicio = paciente, A1). La
    // autorización se revisa en el controlador y en CitaService. /crear, /fichas-disponibles y
    // /buscar-pacientes van antes de /{id}, que además solo acepta números.
    // update = CU16 Reprogramar (y /reasignar, A1); delete = CU17 Cancelar (no elimina el registro);
    // /estado = CU18 Registrar asistencia (confirmar, atendida, inasistencia). El id va oculto.
    // /agenda = CU19 Consultar agenda médica (agenda.consultar o agenda.propia).
    Route::prefix("citas")->controller(CitaController::class)->group(function(){
        Route::get('/','index')->name("citas.listar");
        Route::get('/agenda','agenda')->name("citas.agenda");
        Route::get('/crear','create')->name("citas.crear");
        Route::get('/fichas-disponibles','fichasDisponibles')->name("citas.fichas");
        Route::get('/buscar-pacientes','buscarPacientes')->name("citas.pacientes");
        Route::post('/','store')->name("citas.guardar");
        Route::post('/update','update')->name("citas.modificar");
        Route::post('/reasignar','reasignar')->name("citas.reasignar");
        Route::post('/delete','delete')->name("citas.eliminar");
        Route::post('/estado','cambiarEstado')->name("citas.estado");
        Route::get('/{id}','show')->whereNumber('id')->name("citas.mostrar");
    });

    // CU20 Consultar historial de citas (citas.historial_consultar o citas.historial_propio; se
    // revisa en el controlador). index = historial del paciente (?paciente_id=); show = trazabilidad
    // de una cita (id de la cita).
    Route::prefix("historiales-cita")->controller(HistorialCitaController::class)->group(function(){
        Route::get('/','index')->name("historiales-cita.listar");
        Route::get('/{id}','show')->whereNumber('id')->name("historiales-cita.mostrar");
    });

    // CU30 Consultar cardex (RF-31) y CU29 Gestionar historia clínica (RF-30). El acceso (médico con
    // cita o el paciente y los menores a su cargo) lo revisa Paciente::accesoClinico. /{id} = id del paciente.
    // La historia no se crea aquí (la abre CU10) ni se elimina.
    Route::prefix("historias-clinicas")->controller(HistoriaClinicaController::class)->group(function(){
        Route::get('/','index')->name("historias-clinicas.listar");
        Route::post('/update','update')->name("historias-clinicas.modificar");
        Route::get('/{id}','show')->whereNumber('id')->name("historias-clinicas.mostrar");
    });

    // CU29: antecedentes de la historia. delete = dar de baja (vigente = false; no se borran).
    Route::prefix("antecedentes")->controller(AntecedenteController::class)->group(function(){
        Route::post('/','store')->name("antecedentes.guardar");
        Route::post('/update','update')->name("antecedentes.modificar");
        Route::post('/delete','delete')->name("antecedentes.eliminar");
    });

    // CU29: alergias de la historia. delete = dar de baja (activa = false; no se borran).
    Route::prefix("alergias")->controller(AlergiaController::class)->group(function(){
        Route::post('/','store')->name("alergias.guardar");
        Route::post('/update','update')->name("alergias.modificar");
        Route::post('/delete','delete')->name("alergias.eliminar");
    });

    // CU31 Atender consulta médica (RF-32): solo el médico de la cita con consultas.gestionar (lo revisa
    // ConsultaService). store = iniciar. Sin delete: una consulta no se elimina y FINALIZADA no se modifica.
    Route::prefix("consultas")->controller(ConsultaController::class)->group(function(){
        Route::get('/','index')->name("consultas.listar");
        Route::post('/','store')->name("consultas.guardar");
        Route::post('/update','update')->name("consultas.modificar");
        Route::post('/finalizar','finalizar')->name("consultas.finalizar");
        Route::get('/{id}','show')->whereNumber('id')->name("consultas.mostrar");
    });

    // CU33 Gestionar catálogo de laboratorio (RF-34): exámenes. delete = desactivar (RN11).
    Route::prefix("tipos-examen")->middleware("can:laboratorio.gestionar")->controller(TipoExamenController::class)->group(function(){
        Route::get('/','index')->name("tipos-examen.listar");
        Route::post('/','store')->name("tipos-examen.guardar");
        Route::post('/update','update')->name("tipos-examen.modificar");
        Route::post('/delete','delete')->name("tipos-examen.eliminar");
        Route::post('/activar','activar')->name("tipos-examen.activar");
        Route::get('/{id}','show')->whereNumber('id')->name("tipos-examen.mostrar");
    });

    // CU33: parámetros del examen (en su ficha). Sin borrado.
    Route::prefix("parametros-laboratorio")->middleware("can:laboratorio.gestionar")->controller(ParametroLaboratorioController::class)->group(function(){
        Route::post('/','store')->name("parametros-laboratorio.guardar");
        Route::post('/update','update')->name("parametros-laboratorio.modificar");
    });

    // CU33: rangos de referencia (en la ficha del examen). Uno usado no se edita: se cierra su vigencia.
    // delete = cerrar vigencia (RN23: no se borra).
    Route::prefix("rangos-referencia")->middleware("can:laboratorio.gestionar")->controller(RangoReferenciaController::class)->group(function(){
        Route::post('/','store')->name("rangos-referencia.guardar");
        Route::post('/update','update')->name("rangos-referencia.modificar");
        Route::post('/delete','delete')->name("rangos-referencia.eliminar");
    });

    // CU34 Solicitar orden de laboratorio (RF-35): desde la consulta; solo su médico (lo revisa
    // LaboratorioService). Sin borrado (trigger): una orden SOLICITADA se anula.
    // delete = anular (RN11: no se borra).
    // CU35 Registrar resultados de laboratorio (RF-36) y CU36 Revisar resultados y evolución (RF-37):
    // lista y ficha de órdenes; qué órdenes ve cada uno lo decide OrdenLaboratorio::alcance (si no, 403).
    Route::prefix("ordenes-laboratorio")->controller(OrdenLaboratorioController::class)->group(function(){
        Route::get('/','index')->name("ordenes-laboratorio.listar");
        Route::post('/','store')->name("ordenes-laboratorio.guardar");
        Route::post('/delete','delete')->name("ordenes-laboratorio.eliminar");
        Route::get('/{id}','show')->whereNumber('id')->name("ordenes-laboratorio.mostrar");
    });

    // CU35 Registrar resultados de laboratorio (RF-36): store = registrar la toma de muestra y los valores.
    // Sin update ni delete: el resultado no se modifica y su rango queda fijo (RN23, RN11).
    Route::prefix("resultados-laboratorio")->middleware("can:laboratorio.cargar_resultados")->controller(ResultadoLaboratorioController::class)->group(function(){
        Route::post('/','store')->name("resultados-laboratorio.guardar");
    });

    // CU32 Gestionar tratamientos y prescripciones (RF-33). store = registrar (consulta EN_CURSO);
    // update = registrar la evolución. Sin delete: el trigger impide borrar (para corregir, se suspende).
    Route::prefix("tratamientos")->controller(TratamientoController::class)->group(function(){
        Route::post('/','store')->name("tratamientos.guardar");
        Route::post('/update','update')->name("tratamientos.modificar");
    });

    // CU32: prescripciones (no se modifican ni se borran; el trigger lo impide).
    Route::prefix("prescripciones")->controller(PrescripcionController::class)->group(function(){
        Route::post('/','store')->name("prescripciones.guardar");
    });

    // CU38 Consultar notificaciones (RF-39): cualquier usuario con sesión, sin permiso especial; cada uno
    // ve y marca solo las de su persona (otra persona → 403, lo revisa NotificacionService).
    Route::prefix("notificaciones")->controller(NotificacionController::class)->group(function(){
        Route::get('/','index')->name("notificaciones.listar");
        Route::post('/leer','leer')->name("notificaciones.leer");
        Route::post('/leer-todas','leerTodas')->name("notificaciones.leer-todas");
    });
});

// CU37 paso 4: avisos nuevos para el navegador (JSON, cada 60 s). Sin el middleware inactividad: la consulta
// automática no cuenta como actividad (CU02 A1); el controlador solo lee la marca y responde 401 si venció.
Route::middleware(["auth", "auth.session", "cuenta.activa", "cambio.password"])->group(function(){
    Route::prefix("notificaciones")->controller(NotificacionController::class)->group(function(){
        Route::get('/nuevas','nuevas')->name("notificaciones.nuevas");
    });
});
