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

    Route::prefix("bitacora")->controller(BitacoraController::class)->group(function(){
        Route::get('/','index')->name("bitacora.listar");
        Route::get('/{id}','show')->name("bitacora.mostrar");
    });

    Route::prefix("configuraciones")->controller(ConfiguracionSistemaController::class)->group(function(){
        Route::get('/','index')->name("configuraciones.listar");
        Route::post('/update','update')->name("configuraciones.modificar");
        Route::get('/{clave}','show')->name("configuraciones.mostrar");
    });

    Route::prefix("recepcionistas")->controller(RecepcionistaController::class)->group(function(){
        Route::get('/','index')->name("recepcionistas.listar");
        Route::post('/','store')->name("recepcionistas.guardar");
        Route::post('/update','update')->name("recepcionistas.modificar");
        Route::post('/delete','delete')->name("recepcionistas.eliminar");
        Route::get('/{id}','show')->name("recepcionistas.mostrar");
    });

    Route::prefix("administradores")->controller(AdministradorController::class)->group(function(){
        Route::get('/','index')->name("administradores.listar");
        Route::post('/','store')->name("administradores.guardar");
        Route::post('/update','update')->name("administradores.modificar");
        Route::post('/delete','delete')->name("administradores.eliminar");
        Route::get('/{id}','show')->name("administradores.mostrar");
    });

    // CU07 Gestionar médicos (RF03). delete = desactivar (RN11).
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

    // CU06 Gestionar especialidades (RF04). delete = desactivar (RN11).
    Route::prefix("especialidades")->middleware("can:especialidades.gestionar")->controller(EspecialidadController::class)->group(function(){
        Route::get('/','index')->name("especialidades.listar");
        Route::post('/','store')->name("especialidades.guardar");
        Route::post('/update','update')->name("especialidades.modificar");
        Route::post('/delete','delete')->name("especialidades.eliminar");
        Route::post('/activar','activar')->name("especialidades.activar");
        Route::get('/{id}','show')->name("especialidades.mostrar");
    });

    Route::prefix("medicos-especialidades")->controller(MedicoEspecialidadController::class)->group(function(){
        Route::get('/','index')->name("medicos-especialidades.listar");
        Route::post('/','store')->name("medicos-especialidades.guardar");
        Route::post('/update','update')->name("medicos-especialidades.modificar");
        Route::post('/delete','delete')->name("medicos-especialidades.eliminar");
        Route::get('/{id}','show')->name("medicos-especialidades.mostrar");
    });

    Route::prefix("aseguradoras")->controller(AseguradoraController::class)->group(function(){
        Route::get('/','index')->name("aseguradoras.listar");
        Route::post('/','store')->name("aseguradoras.guardar");
        Route::post('/update','update')->name("aseguradoras.modificar");
        Route::post('/delete','delete')->name("aseguradoras.eliminar");
        Route::get('/{id}','show')->name("aseguradoras.mostrar");
    });

    Route::prefix("seguros-paciente")->controller(PacienteSeguroController::class)->group(function(){
        Route::get('/','index')->name("seguros-paciente.listar");
        Route::post('/','store')->name("seguros-paciente.guardar");
        Route::post('/update','update')->name("seguros-paciente.modificar");
        Route::post('/delete','delete')->name("seguros-paciente.eliminar");
        Route::get('/{id}','show')->name("seguros-paciente.mostrar");
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

    // CU09 Gestionar horarios de atención (RF05). Sin update: un horario no se edita, se cierra
    // su vigencia (delete, A1) y se registra uno nuevo. generar = más semanas de fichas (A2).
    Route::prefix("horarios")->middleware("can:horarios.gestionar")->controller(HorarioAtencionController::class)->group(function(){
        Route::get('/','index')->name("horarios.listar");
        Route::post('/','store')->name("horarios.guardar");
        Route::post('/delete','delete')->name("horarios.eliminar");
        Route::post('/generar','generarFichas')->name("horarios.generar");
        Route::get('/{id}','show')->name("horarios.mostrar");
    });

    Route::prefix("bloqueos")->controller(BloqueoAgendaController::class)->group(function(){
        Route::get('/','index')->name("bloqueos.listar");
        Route::post('/','store')->name("bloqueos.guardar");
        Route::post('/update','update')->name("bloqueos.modificar");
        Route::post('/delete','delete')->name("bloqueos.eliminar");
        Route::get('/{id}','show')->name("bloqueos.mostrar");
    });

    Route::prefix("fichas")->controller(FichaController::class)->group(function(){
        Route::get('/','index')->name("fichas.listar");
        Route::post('/','store')->name("fichas.guardar");
        Route::post('/update','update')->name("fichas.modificar");
        Route::post('/delete','delete')->name("fichas.eliminar");
        Route::get('/{id}','show')->name("fichas.mostrar");
    });

    Route::prefix("citas")->controller(CitaController::class)->group(function(){
        Route::get('/','index')->name("citas.listar");
        Route::post('/','store')->name("citas.guardar");
        Route::post('/update','update')->name("citas.modificar");
        Route::post('/delete','delete')->name("citas.eliminar");
        Route::get('/{id}','show')->name("citas.mostrar");
    });

    Route::prefix("historiales-cita")->controller(HistorialCitaController::class)->group(function(){
        Route::get('/','index')->name("historiales-cita.listar");
        Route::get('/{id}','show')->name("historiales-cita.mostrar");
    });

    Route::prefix("historias-clinicas")->controller(HistoriaClinicaController::class)->group(function(){
        Route::get('/','index')->name("historias-clinicas.listar");
        Route::post('/','store')->name("historias-clinicas.guardar");
        Route::post('/update','update')->name("historias-clinicas.modificar");
        Route::post('/delete','delete')->name("historias-clinicas.eliminar");
        Route::get('/{id}','show')->name("historias-clinicas.mostrar");
    });

    Route::prefix("antecedentes")->controller(AntecedenteController::class)->group(function(){
        Route::get('/','index')->name("antecedentes.listar");
        Route::post('/','store')->name("antecedentes.guardar");
        Route::post('/update','update')->name("antecedentes.modificar");
        Route::post('/delete','delete')->name("antecedentes.eliminar");
        Route::get('/{id}','show')->name("antecedentes.mostrar");
    });

    Route::prefix("alergias")->controller(AlergiaController::class)->group(function(){
        Route::get('/','index')->name("alergias.listar");
        Route::post('/','store')->name("alergias.guardar");
        Route::post('/update','update')->name("alergias.modificar");
        Route::post('/delete','delete')->name("alergias.eliminar");
        Route::get('/{id}','show')->name("alergias.mostrar");
    });

    Route::prefix("consultas")->controller(ConsultaController::class)->group(function(){
        Route::get('/','index')->name("consultas.listar");
        Route::post('/','store')->name("consultas.guardar");
        Route::post('/update','update')->name("consultas.modificar");
        Route::post('/delete','delete')->name("consultas.eliminar");
        Route::get('/{id}','show')->name("consultas.mostrar");
    });

    Route::prefix("tipos-examen")->controller(TipoExamenController::class)->group(function(){
        Route::get('/','index')->name("tipos-examen.listar");
        Route::post('/','store')->name("tipos-examen.guardar");
        Route::post('/update','update')->name("tipos-examen.modificar");
        Route::post('/delete','delete')->name("tipos-examen.eliminar");
        Route::get('/{id}','show')->name("tipos-examen.mostrar");
    });

    Route::prefix("parametros-laboratorio")->controller(ParametroLaboratorioController::class)->group(function(){
        Route::get('/','index')->name("parametros-laboratorio.listar");
        Route::post('/','store')->name("parametros-laboratorio.guardar");
        Route::post('/update','update')->name("parametros-laboratorio.modificar");
        Route::post('/delete','delete')->name("parametros-laboratorio.eliminar");
        Route::get('/{id}','show')->name("parametros-laboratorio.mostrar");
    });

    Route::prefix("rangos-referencia")->controller(RangoReferenciaController::class)->group(function(){
        Route::get('/','index')->name("rangos-referencia.listar");
        Route::post('/','store')->name("rangos-referencia.guardar");
        Route::post('/update','update')->name("rangos-referencia.modificar");
        Route::post('/delete','delete')->name("rangos-referencia.eliminar");
        Route::get('/{id}','show')->name("rangos-referencia.mostrar");
    });

    Route::prefix("ordenes-laboratorio")->controller(OrdenLaboratorioController::class)->group(function(){
        Route::get('/','index')->name("ordenes-laboratorio.listar");
        Route::post('/','store')->name("ordenes-laboratorio.guardar");
        Route::post('/update','update')->name("ordenes-laboratorio.modificar");
        Route::post('/delete','delete')->name("ordenes-laboratorio.eliminar");
        Route::get('/{id}','show')->name("ordenes-laboratorio.mostrar");
    });

    Route::prefix("resultados-laboratorio")->controller(ResultadoLaboratorioController::class)->group(function(){
        Route::get('/','index')->name("resultados-laboratorio.listar");
        Route::post('/','store')->name("resultados-laboratorio.guardar");
        Route::post('/update','update')->name("resultados-laboratorio.modificar");
        Route::post('/delete','delete')->name("resultados-laboratorio.eliminar");
        Route::get('/{id}','show')->name("resultados-laboratorio.mostrar");
    });

    Route::prefix("tratamientos")->controller(TratamientoController::class)->group(function(){
        Route::get('/','index')->name("tratamientos.listar");
        Route::post('/','store')->name("tratamientos.guardar");
        Route::post('/update','update')->name("tratamientos.modificar");
        Route::post('/delete','delete')->name("tratamientos.eliminar");
        Route::get('/{id}','show')->name("tratamientos.mostrar");
    });

    Route::prefix("prescripciones")->controller(PrescripcionController::class)->group(function(){
        Route::get('/','index')->name("prescripciones.listar");
        Route::post('/','store')->name("prescripciones.guardar");
        Route::post('/update','update')->name("prescripciones.modificar");
        Route::post('/delete','delete')->name("prescripciones.eliminar");
        Route::get('/{id}','show')->name("prescripciones.mostrar");
    });

    Route::prefix("notificaciones")->controller(NotificacionController::class)->group(function(){
        Route::get('/','index')->name("notificaciones.listar");
        Route::post('/','store')->name("notificaciones.guardar");
        Route::post('/update','update')->name("notificaciones.modificar");
        Route::post('/delete','delete')->name("notificaciones.eliminar");
        Route::get('/{id}','show')->name("notificaciones.mostrar");
    });
});
