{{-- Menú lateral (RF-05: menú según los permisos). Cada opción se protege con su @can / @if y cada
     sección se muestra solo si el usuario puede ver al menos una de sus opciones.
     En pantallas pequeñas es un cajón que se abre con el botón de la barra superior (menuAbierto). --}}
@php
    $usuarioMenu = Auth::user()->loadMissing(['persona.paciente', 'rol']);
    // CU13 A1 / CU28: el paciente gestiona sus propios contactos y su perfil.
    $verMisContactos = $usuarioMenu->persona->paciente !== null && Gate::allows('perfil.gestionar');
    // CU30: el médico (historia.consultar y perfil de médico ACTIVO) ve «Mis pacientes»; el paciente, «Mi cardex».
    $verMisPacientes = \App\Models\Paciente::medicoClinico($usuarioMenu) !== null;
    $verMiCardex = ! $verMisPacientes && Gate::allows('historia.propia');
    // CU31: el médico (consultas.gestionar y perfil de médico ACTIVO) ve «Mis consultas».
    $verMisConsultas = \App\Models\Consulta::medicoQueAtiende($usuarioMenu) !== null;
@endphp

<!-- Fondo oscuro detrás del cajón (celular y tablet) -->
<div x-show="menuAbierto" x-cloak
        x-transition.opacity
        @click="menuAbierto = false"
        class="fixed inset-0 z-40 bg-marino-900/60 lg:hidden"></div>

<aside :style="menuAbierto ? 'transform: translateX(0)' : ''"
        class="fixed inset-y-0 start-0 z-50 flex w-72 flex-col bg-marino-900 transition-transform duration-200 ease-in-out max-lg:-translate-x-full">
    <!-- Logo y nombre -->
    <div class="flex items-center justify-between border-b border-marino-700 px-6 py-5">
        <a href="{{ route('panel') }}" class="flex items-center gap-3">
            <x-logo />
            <span>
                <span class="block text-lg font-bold leading-tight text-white">Vida Salud</span>
                <span class="block text-sm text-slate-300">Gestión de citas</span>
            </span>
        </a>

        <!-- Cerrar el cajón (celular y tablet) -->
        <button type="button" @click="menuAbierto = false" class="rounded-lg p-1.5 text-slate-300 hover:bg-marino-800 hover:text-white lg:hidden" aria-label="Cerrar el menú">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <!-- Opciones del menú -->
    <nav class="flex-1 overflow-y-auto pb-6">
        <x-menu-seccion>Inicio</x-menu-seccion>
        <x-menu-enlace :href="route('panel')" :active="request()->routeIs('panel')">
            Panel
        </x-menu-enlace>

        {{-- CU38 Consultar notificaciones: todos los usuarios con sesión, con el número de no leídas (una sola
             consulta de conteo por petición; notificaciones.js lo actualiza cada minuto) --}}
        @php $noLeidasMenu = \App\Models\Notificacion::contarNoLeidas($usuarioMenu); @endphp
        <x-menu-enlace :href="route('notificaciones.listar')" :active="request()->routeIs('notificaciones.*')">
            Notificaciones
            <span data-contador-notificaciones @class(['ms-2 inline-flex min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 text-xs font-bold text-white', 'hidden' => $noLeidasMenu === 0])
                  aria-label="{{ $noLeidasMenu }} sin leer">{{ $noLeidasMenu > 99 ? '99+' : $noLeidasMenu }}</span>
        </x-menu-enlace>

        {{-- CITAS --}}
        <x-menu-seccion>Citas</x-menu-seccion>

        {{-- CU14 Consultar disponibilidad de fichas (paciente y recepcionista) --}}
        <x-menu-enlace :href="route('fichas.disponibilidad')" :active="request()->routeIs('fichas.*')">
            Médicos y horarios
        </x-menu-enlace>

        {{-- CU15 Programar cita (recepción) / A1 Reservar cita (paciente) --}}
        @can('citas.programar')
            <x-menu-enlace :href="route('citas.crear')" :active="request()->routeIs('citas.crear')">
                Programar cita
            </x-menu-enlace>
        @elsecan('citas.autoservicio')
            <x-menu-enlace :href="route('citas.crear')" :active="request()->routeIs('citas.crear')">
                Reservar cita
            </x-menu-enlace>
        @endcan

        {{-- CU26 Autorizar excepción de edad (formulario de CU15 en modo excepción) --}}
        @can('citas.autorizar_excepcion')
            <x-menu-enlace :href="route('citas.crear', ['excepcion' => 1])" :active="request()->routeIs('citas.crear') && request()->boolean('excepcion')">
                Excepción de edad
            </x-menu-enlace>
        @endcan

        {{-- Citas del día --}}
        @canany(['citas.programar', 'agenda.consultar'])
            <x-menu-enlace :href="route('citas.listar')" :active="request()->routeIs('citas.listar', 'citas.mostrar')">
                Citas
            </x-menu-enlace>
        @endcanany

        {{-- CU19 Consultar agenda médica: cualquier médico (agenda.consultar) o la propia (agenda.propia;
             reemplaza «Mis citas de hoy»: los botones de CU18 están en la agenda) --}}
        @can('agenda.consultar')
            <x-menu-enlace :href="route('citas.agenda')" :active="request()->routeIs('citas.agenda')">
                Agenda
            </x-menu-enlace>
        @elsecan('agenda.propia')
            @if (Auth::user()->persona?->medico)
                <x-menu-enlace :href="route('citas.agenda')" :active="request()->routeIs('citas.agenda')">
                    Mi agenda
                </x-menu-enlace>
            @endif
        @endcan

        {{-- CU20 A1: historial de citas propio (y de los menores a cargo) --}}
        @can('citas.historial_propio')
            <x-menu-enlace :href="route('historiales-cita.listar')" :active="request()->routeIs('historiales-cita.*')">
                Mis citas
            </x-menu-enlace>
        @endcan

        {{-- PACIENTES --}}
        @if (Gate::allows('pacientes.buscar') || $verMisContactos)
            <x-menu-seccion>Pacientes</x-menu-seccion>

            {{-- CU11 Buscar paciente / CU10 Gestionar pacientes --}}
            @can('pacientes.buscar')
                <x-menu-enlace :href="route('pacientes.listar')" :active="request()->routeIs('pacientes.*')">
                    Pacientes
                </x-menu-enlace>
            @endcan

            {{-- CU13 A1: contactos del propio paciente --}}
            @if ($verMisContactos)
                <x-menu-enlace :href="route('vinculos-paciente.listar')" :active="request()->routeIs('vinculos-paciente.*')">
                    Mis contactos
                </x-menu-enlace>

                {{-- CU28 Gestionar perfil del paciente (perfil.gestionar y perfil de paciente) --}}
                <x-menu-enlace :href="route('perfil.mostrar')" :active="request()->routeIs('perfil.*')">
                    Mi perfil
                </x-menu-enlace>
            @endif
        @endif

        {{-- ATENCIÓN CLÍNICA --}}
        @if ($verMisPacientes || $verMiCardex || $verMisConsultas)
            <x-menu-seccion>Atención clínica</x-menu-seccion>

            {{-- CU30 Consultar cardex: médico («Mis pacientes») o paciente («Mi cardex») --}}
            @if ($verMisPacientes || $verMiCardex)
                <x-menu-enlace :href="route('historias-clinicas.listar')" :active="request()->routeIs('historias-clinicas.*')">
                    {{ $verMisPacientes ? 'Mis pacientes' : 'Mi cardex' }}
                </x-menu-enlace>
            @endif

            {{-- CU31 Atender consulta médica: «Mis consultas» del médico --}}
            @if ($verMisConsultas)
                <x-menu-enlace :href="route('consultas.listar')" :active="request()->routeIs('consultas.*')">
                    Mis consultas
                </x-menu-enlace>
            @endif
        @endif

        {{-- LABORATORIO --}}
        @canany(['laboratorio.gestionar', 'laboratorio.cargar_resultados', 'laboratorio.consultar_resultados', 'resultados.propios'])
            <x-menu-seccion>Laboratorio</x-menu-seccion>

            {{-- CU33 Gestionar catálogo de laboratorio --}}
            @can('laboratorio.gestionar')
                <x-menu-enlace :href="route('tipos-examen.listar')" :active="request()->routeIs('tipos-examen.*')">
                    Catálogo de laboratorio
                </x-menu-enlace>
            @endcan

            {{-- CU35 Registrar resultados / CU36 Revisar resultados y evolución; el paciente ve «Mis resultados» --}}
            @canany(['laboratorio.cargar_resultados', 'laboratorio.consultar_resultados'])
                <x-menu-enlace :href="route('ordenes-laboratorio.listar')" :active="request()->routeIs('ordenes-laboratorio.*')">
                    Órdenes de laboratorio
                </x-menu-enlace>
            @else
                @can('resultados.propios')
                    <x-menu-enlace :href="route('ordenes-laboratorio.listar')" :active="request()->routeIs('ordenes-laboratorio.*')">
                        Mis resultados
                    </x-menu-enlace>
                @endcan
            @endcanany
        @endcanany

        {{-- CATÁLOGOS --}}
        @canany(['especialidades.gestionar', 'medicos.gestionar', 'consultorios.gestionar', 'horarios.gestionar', 'bloqueos.gestionar', 'aseguradoras.gestionar'])
            <x-menu-seccion>Catálogos</x-menu-seccion>

            {{-- CU06 Gestionar especialidades --}}
            @can('especialidades.gestionar')
                <x-menu-enlace :href="route('especialidades.listar')" :active="request()->routeIs('especialidades.*')">
                    Especialidades
                </x-menu-enlace>
            @endcan

            {{-- CU07 Gestionar médicos --}}
            @can('medicos.gestionar')
                <x-menu-enlace :href="route('medicos.listar')" :active="request()->routeIs('medicos.*')">
                    Médicos
                </x-menu-enlace>
            @endcan

            {{-- CU08 Gestionar consultorios --}}
            @can('consultorios.gestionar')
                <x-menu-enlace :href="route('consultorios.listar')" :active="request()->routeIs('consultorios.*')">
                    Consultorios
                </x-menu-enlace>
            @endcan

            {{-- CU09 Gestionar horarios de atención --}}
            @can('horarios.gestionar')
                <x-menu-enlace :href="route('horarios.listar')" :active="request()->routeIs('horarios.*')">
                    Horarios
                </x-menu-enlace>
            @endcan

            {{-- CU25 Gestionar bloqueos de agenda --}}
            @can('bloqueos.gestionar')
                <x-menu-enlace :href="route('bloqueos.listar')" :active="request()->routeIs('bloqueos.*')">
                    Bloqueos de agenda
                </x-menu-enlace>
            @endcan

            {{-- CU23 Gestionar aseguradoras --}}
            @can('aseguradoras.gestionar')
                <x-menu-enlace :href="route('aseguradoras.listar')" :active="request()->routeIs('aseguradoras.*')">
                    Aseguradoras
                </x-menu-enlace>
            @endcan
        @endcanany

        {{-- CU40 Generar reportes (Administrador y Recepcionista) --}}
        @can('reportes.consultar')
            <x-menu-seccion>Gestión</x-menu-seccion>
            <x-menu-enlace :href="route('reportes.listar')" :active="request()->routeIs('reportes.*')">
                Reportes
            </x-menu-enlace>
        @endcan

        {{-- SEGURIDAD --}}
        @canany(['usuarios.gestionar', 'roles.gestionar', 'bitacora.consultar', 'configuracion.gestionar'])
            <x-menu-seccion>Seguridad</x-menu-seccion>

            {{-- CU04 Gestionar usuarios --}}
            @can('usuarios.gestionar')
                <x-menu-enlace :href="route('usuarios.listar')" :active="request()->routeIs('usuarios.*')">
                    Usuarios
                </x-menu-enlace>
            @endcan

            {{-- CU05 Gestionar roles y permisos --}}
            @can('roles.gestionar')
                <x-menu-enlace :href="route('roles.listar')" :active="request()->routeIs('roles.*')">
                    Roles y permisos
                </x-menu-enlace>
            @endcan

            {{-- CU21 Consultar bitácora --}}
            @can('bitacora.consultar')
                <x-menu-enlace :href="route('bitacora.listar')" :active="request()->routeIs('bitacora.*')">
                    Bitácora
                </x-menu-enlace>
            @endcan

            {{-- CU22 Gestionar configuración del sistema --}}
            @can('configuracion.gestionar')
                <x-menu-enlace :href="route('configuraciones.listar')" :active="request()->routeIs('configuraciones.*')">
                    Configuración del sistema
                </x-menu-enlace>
            @endcan
        @endcanany
    </nav>
</aside>
