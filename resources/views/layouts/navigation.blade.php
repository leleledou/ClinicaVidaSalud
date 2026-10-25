{{-- Menú principal (RF01: menú según los permisos). Por ahora solo «Panel»;
     las opciones de los próximos bloques se agregan con @can("codigo.permiso"). --}}
@php
    $usuarioMenu = Auth::user()->loadMissing(['persona', 'rol']);
    $nombreMenu = $usuarioMenu->persona->nombres . ' ' . $usuarioMenu->persona->apellido_paterno;
@endphp

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100">
    <!-- Menú de navegación principal -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo y nombre -->
                <div class="shrink-0 flex items-center">
                    <a href="{{ route('panel') }}" class="flex items-center gap-2">
                        <x-application-logo class="block h-9 w-auto fill-current text-gray-800" />
                        <span class="font-semibold text-gray-800">Clínica Vida Salud</span>
                    </a>
                </div>

                <!-- Opciones del menú -->
                <div class="hidden space-x-6 lg:-my-px lg:ms-8 lg:flex">
                    <x-nav-link :href="route('panel')" :active="request()->routeIs('panel')">
                        Panel
                    </x-nav-link>

                    {{-- CU06 Gestionar especialidades --}}
                    @can('especialidades.gestionar')
                        <x-nav-link :href="route('especialidades.listar')" :active="request()->routeIs('especialidades.*')">
                            Especialidades
                        </x-nav-link>
                    @endcan

                    {{-- CU08 Gestionar consultorios --}}
                    @can('consultorios.gestionar')
                        <x-nav-link :href="route('consultorios.listar')" :active="request()->routeIs('consultorios.*')">
                            Consultorios
                        </x-nav-link>
                    @endcan

                    {{-- CU07 Gestionar médicos --}}
                    @can('medicos.gestionar')
                        <x-nav-link :href="route('medicos.listar')" :active="request()->routeIs('medicos.*')">
                            Médicos
                        </x-nav-link>
                    @endcan

                    {{-- CU11 Buscar paciente / CU10 Gestionar pacientes --}}
                    @can('pacientes.buscar')
                        <x-nav-link :href="route('pacientes.listar')" :active="request()->routeIs('pacientes.*')">
                            Pacientes
                        </x-nav-link>
                    @endcan

                    {{-- CU04 Gestionar usuarios --}}
                    @can('usuarios.gestionar')
                        <x-nav-link :href="route('usuarios.listar')" :active="request()->routeIs('usuarios.*')">
                            Usuarios
                        </x-nav-link>
                    @endcan

                    {{-- CU05 Gestionar roles y permisos --}}
                    @can('roles.gestionar')
                        <x-nav-link :href="route('roles.listar')" :active="request()->routeIs('roles.*')">
                            Roles y permisos
                        </x-nav-link>
                    @endcan
                </div>
            </div>

            <!-- Menú de la cuenta -->
            <div class="hidden lg:flex lg:items-center lg:ms-6">
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-gray-500 bg-white hover:text-gray-700 focus:outline-none transition ease-in-out duration-150">
                            <div>{{ $nombreMenu }}</div>

                            <div class="ms-1">
                                <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </div>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <!-- Usuario y rol -->
                        <div class="px-4 py-2 text-xs text-gray-500 border-b border-gray-100">
                            <div>{{ $usuarioMenu->nombre_usuario }}</div>
                            <div>{{ $usuarioMenu->rol->nombre }}</div>
                        </div>

                        <!-- CU03 Cambiar contraseña -->
                        <x-dropdown-link :href="route('password.editar')">
                            Cambiar contraseña
                        </x-dropdown-link>

                        <!-- CU02 Cerrar sesión (POST con token CSRF) -->
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                Cerrar sesión
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Botón del menú móvil -->
            <div class="-me-2 flex items-center lg:hidden">
                <button @click="open = ! open" class="inline-flex items-center justify-center p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:bg-gray-100 focus:text-gray-500 transition duration-150 ease-in-out">
                    <svg class="h-6 w-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{'hidden': open, 'inline-flex': ! open }" class="inline-flex" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{'hidden': ! open, 'inline-flex': open }" class="hidden" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Menú móvil -->
    <div :class="{'block': open, 'hidden': ! open}" class="hidden lg:hidden">
        <div class="pt-2 pb-3 space-y-1">
            <x-responsive-nav-link :href="route('panel')" :active="request()->routeIs('panel')">
                Panel
            </x-responsive-nav-link>

            @can('especialidades.gestionar')
                <x-responsive-nav-link :href="route('especialidades.listar')" :active="request()->routeIs('especialidades.*')">
                    Especialidades
                </x-responsive-nav-link>
            @endcan

            @can('consultorios.gestionar')
                <x-responsive-nav-link :href="route('consultorios.listar')" :active="request()->routeIs('consultorios.*')">
                    Consultorios
                </x-responsive-nav-link>
            @endcan

            @can('medicos.gestionar')
                <x-responsive-nav-link :href="route('medicos.listar')" :active="request()->routeIs('medicos.*')">
                    Médicos
                </x-responsive-nav-link>
            @endcan

            @can('pacientes.buscar')
                <x-responsive-nav-link :href="route('pacientes.listar')" :active="request()->routeIs('pacientes.*')">
                    Pacientes
                </x-responsive-nav-link>
            @endcan

            @can('usuarios.gestionar')
                <x-responsive-nav-link :href="route('usuarios.listar')" :active="request()->routeIs('usuarios.*')">
                    Usuarios
                </x-responsive-nav-link>
            @endcan

            @can('roles.gestionar')
                <x-responsive-nav-link :href="route('roles.listar')" :active="request()->routeIs('roles.*')">
                    Roles y permisos
                </x-responsive-nav-link>
            @endcan
        </div>

        <!-- Opciones de la cuenta (móvil) -->
        <div class="pt-4 pb-1 border-t border-gray-200">
            <div class="px-4">
                <div class="font-medium text-base text-gray-800">{{ $nombreMenu }}</div>
                <div class="font-medium text-sm text-gray-500">{{ $usuarioMenu->nombre_usuario }} · {{ $usuarioMenu->rol->nombre }}</div>
            </div>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('password.editar')" :active="request()->routeIs('password.editar')">
                    Cambiar contraseña
                </x-responsive-nav-link>

                <!-- CU02 Cerrar sesión (POST con token CSRF) -->
                <form method="POST" action="{{ route('logout') }}">
                    @csrf

                    <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault();
                                        this.closest('form').submit();">
                        Cerrar sesión
                    </x-responsive-nav-link>
                </form>
            </div>
        </div>
    </div>
</nav>
