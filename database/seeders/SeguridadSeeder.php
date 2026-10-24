<?php

namespace Database\Seeders;

use Database\Seeders\Support\Siembra;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Roles, permisos y roles_permisos (UML: Rol OTORGA Permiso).
 *
 * Se agrupan en un solo seeder porque forman una unidad: la tabla intermedia
 * solo puede poblarse con los dos catálogos ya presentes.
 *
 * El PUDS-UML menciona 41 permisos y 58 asignaciones, pero solo nombra 13
 * códigos (los marcados «doc»). Los demás están DERIVADOS de los casos de uso y
 * de los módulos del documento: son una propuesta, no un catálogo aprobado.
 */
class SeguridadSeeder extends Seeder
{
    private const ROLES = [
        'ADMINISTRADOR' => 'Gestiona la configuración, los catálogos, los usuarios y la agenda de la clínica.',
        'RECEPCIONISTA' => 'Registra pacientes y gestiona citas, vínculos y seguros.',
        'MEDICO' => 'Consulta su agenda, registra la atención y accede a la información clínica de sus pacientes.',
        'PACIENTE' => 'Reserva y consulta sus propias citas y su información personal.',
    ];

    /** código => [nombre, módulo, origen] — «doc» = nombrado en el PUDS-UML; «derivado» = propuesta. */
    private const PERMISOS = [
        // Seguridad
        'usuarios.gestionar' => ['Gestionar usuarios', 'seguridad', 'doc'],
        'roles.gestionar' => ['Gestionar roles y permisos', 'seguridad', 'doc'],
        'bitacora.consultar' => ['Consultar la bitácora', 'seguridad', 'derivado'],
        'configuracion.gestionar' => ['Gestionar parámetros del sistema', 'seguridad', 'derivado'],
        // Catálogos
        'especialidades.gestionar' => ['Gestionar especialidades', 'catalogos', 'doc'],
        'medicos.gestionar' => ['Gestionar médicos', 'catalogos', 'doc'],
        'consultorios.gestionar' => ['Gestionar consultorios', 'catalogos', 'doc'],
        'aseguradoras.gestionar' => ['Gestionar aseguradoras', 'catalogos', 'derivado'],
        'bloqueos.gestionar' => ['Gestionar bloqueos de agenda', 'agenda', 'derivado'],
        // Agenda
        'horarios.gestionar' => ['Gestionar horarios de atención', 'agenda', 'derivado'],
        'fichas.consultar' => ['Consultar disponibilidad de fichas', 'agenda', 'derivado'],
        'agenda.consultar' => ['Consultar la agenda de cualquier médico', 'agenda', 'doc'],
        'agenda.propia' => ['Consultar la agenda propia', 'agenda', 'doc'],
        // Pacientes
        'pacientes.gestionar' => ['Gestionar pacientes', 'pacientes', 'doc'],
        'pacientes.buscar' => ['Buscar pacientes', 'pacientes', 'doc'],
        'perfil.gestionar' => ['Gestionar el perfil propio', 'pacientes', 'derivado'],
        'vinculos.gestionar' => ['Gestionar vínculos del paciente', 'pacientes', 'derivado'],
        'vinculos.verificar' => ['Verificar vínculos del paciente', 'pacientes', 'derivado'],
        'seguros.gestionar' => ['Gestionar seguros del paciente', 'pacientes', 'derivado'],
        'seguros.verificar' => ['Verificar seguros del paciente', 'pacientes', 'derivado'],
        // Citas
        'citas.programar' => ['Programar citas', 'citas', 'doc'],
        'citas.autoservicio' => ['Reservar, reprogramar y cancelar citas propias', 'citas', 'doc'],
        'citas.reprogramar' => ['Reprogramar citas', 'citas', 'derivado'],
        'citas.cancelar' => ['Cancelar citas', 'citas', 'derivado'],
        'citas.confirmar' => ['Confirmar la asistencia a una cita', 'citas', 'doc'],
        'citas.registrar_asistencia' => ['Registrar atención o inasistencia', 'citas', 'doc'],
        'citas.autorizar_excepcion' => ['Autorizar excepciones de edad o sexo', 'citas', 'derivado'],
        'citas.historial_consultar' => ['Consultar el historial de citas de cualquier paciente', 'citas', 'derivado'],
        'citas.historial_propio' => ['Consultar el historial de citas propio', 'citas', 'derivado'],
        // Atención clínica (Ciclo 2)
        'historia.consultar' => ['Consultar historias clínicas', 'clinica', 'derivado'],
        'historia.gestionar' => ['Gestionar historias clínicas', 'clinica', 'derivado'],
        'historia.propia' => ['Consultar la historia clínica propia', 'clinica', 'derivado'],
        'consultas.gestionar' => ['Gestionar consultas médicas', 'clinica', 'derivado'],
        'tratamientos.gestionar' => ['Gestionar tratamientos y prescripciones', 'clinica', 'derivado'],
        // Laboratorio (Ciclo 2)
        'laboratorio.gestionar' => ['Gestionar catálogos de laboratorio', 'laboratorio', 'derivado'],
        'laboratorio.cargar_resultados' => ['Cargar resultados de laboratorio', 'laboratorio', 'derivado'],
        'laboratorio.consultar_resultados' => ['Consultar resultados de laboratorio', 'laboratorio', 'derivado'],
        'resultados.propios' => ['Consultar los resultados propios', 'laboratorio', 'derivado'],
        // Transversales
        'notificaciones.gestionar' => ['Gestionar notificaciones', 'notificaciones', 'derivado'],
        'reportes.consultar' => ['Consultar reportes de gestión', 'reportes', 'derivado'],
        'voz.usar' => ['Usar comandos por voz', 'voz', 'derivado'],
    ];

    /** Nadie recibe todos los permisos: cada rol solo los de su función. */
    private const ASIGNACION = [
        'ADMINISTRADOR' => [
            'usuarios.gestionar', 'roles.gestionar', 'bitacora.consultar', 'configuracion.gestionar',
            'especialidades.gestionar', 'medicos.gestionar', 'consultorios.gestionar', 'aseguradoras.gestionar',
            'bloqueos.gestionar', 'horarios.gestionar', 'fichas.consultar', 'agenda.consultar',
            'pacientes.buscar', 'citas.autorizar_excepcion', 'citas.historial_consultar',
            'laboratorio.gestionar', 'notificaciones.gestionar', 'reportes.consultar',
        ],
        'RECEPCIONISTA' => [
            'fichas.consultar', 'agenda.consultar', 'pacientes.gestionar', 'pacientes.buscar',
            'vinculos.gestionar', 'vinculos.verificar', 'seguros.gestionar', 'seguros.verificar',
            'citas.programar', 'citas.reprogramar', 'citas.cancelar', 'citas.confirmar',
            'citas.registrar_asistencia', 'citas.historial_consultar', 'voz.usar',
        ],
        'MEDICO' => [
            'agenda.propia', 'fichas.consultar', 'pacientes.buscar', 'citas.registrar_asistencia',
            'citas.historial_consultar', 'historia.consultar', 'historia.gestionar', 'consultas.gestionar',
            'tratamientos.gestionar', 'laboratorio.cargar_resultados', 'laboratorio.consultar_resultados', 'voz.usar',
        ],
        'PACIENTE' => [
            'fichas.consultar', 'perfil.gestionar', 'citas.autoservicio', 'citas.historial_propio',
            'historia.propia', 'resultados.propios', 'voz.usar',
        ],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::ROLES as $nombre => $descripcion) {
                DB::table('roles')->insertOrIgnore(['nombre' => $nombre, 'descripcion' => $descripcion, 'estado' => 'ACTIVO']);
            }
            foreach (self::PERMISOS as $codigo => [$nombre, $modulo]) {
                DB::table('permisos')->insertOrIgnore(['codigo' => $codigo, 'nombre' => $nombre, 'modulo' => $modulo]);
            }

            foreach (self::ASIGNACION as $rol => $codigos) {
                $rolId = Siembra::rolId($rol);
                foreach ($codigos as $codigo) {
                    $permisoId = Siembra::id('permisos', ['codigo' => $codigo])
                        ?? throw new RuntimeException("Permiso inexistente en el catálogo: {$codigo}");
                    DB::table('roles_permisos')->insertOrIgnore(['rol_id' => $rolId, 'permiso_id' => $permisoId]);
                }
            }
        });
    }
}
