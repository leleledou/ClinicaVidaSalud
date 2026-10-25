{{-- CU01 paso 8: panel de inicio. Saluda a la persona, muestra el resumen y los accesos rápidos según sus permisos. --}}
<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Inicio" titulo="Panel" />
    </x-slot>

    @php
        $fechaHoy = now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');

        // Accesos rápidos: cada uno con la misma condición que su opción del menú lateral.
        $accesos = [];
        if (Gate::allows('pacientes.buscar') && Gate::allows('pacientes.gestionar')) {
            $accesos[] = ['+ Nuevo paciente', route('pacientes.listar')];
        }
        if (Gate::allows('citas.programar')) {
            $accesos[] = ['Programar cita', route('citas.crear')];
        } elseif (Gate::allows('citas.autoservicio')) {
            $accesos[] = ['Reservar cita', route('citas.crear')];
        }
        if (! Gate::allows('agenda.consultar') && Gate::allows('agenda.propia') && Auth::user()->persona?->medico) {
            $accesos[] = ['Mi agenda', route('citas.agenda')];
        }
        if (Gate::allows('citas.historial_propio')) {
            $accesos[] = ['Mis citas', route('historiales-cita.listar')];
        }
        if (Gate::allows('pacientes.buscar') && ! Gate::allows('pacientes.gestionar')) {
            $accesos[] = ['Pacientes', route('pacientes.listar')];
        }
        $accesos[] = ['Ver disponibilidad', route('fichas.disponibilidad')];
        if (Gate::allows('agenda.consultar')) {
            $accesos[] = ['Agenda del día', route('citas.agenda')];
        }
        if (Gate::allows('usuarios.gestionar')) {
            $accesos[] = ['Usuarios', route('usuarios.listar')];
        }
        if (Gate::allows('medicos.gestionar')) {
            $accesos[] = ['Médicos', route('medicos.listar')];
        }
        if (Gate::allows('horarios.gestionar')) {
            $accesos[] = ['Horarios', route('horarios.listar')];
        }
    @endphp

    <div class="alerta-info">
        Bienvenido(a), <span class="font-semibold">{{ $usuario->persona->nombres }}</span>. Hoy es {{ $fechaHoy }}.
    </div>

    {{-- CU12: el paciente con el correo sin verificar no puede reservar citas en línea --}}
    @if ($correoPendiente)
        <div class="alerta-aviso font-medium">
            Verifica tu correo para poder reservar citas en línea.
            <a href="{{ route('registro.reenviar') }}" class="ms-1 font-semibold underline">Reenviar el enlace de verificación</a>
        </div>
    @endif

    @if (! empty($resumen))
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($resumen as $etiqueta => $numero)
                <div class="tarjeta">
                    <p class="text-3xl font-bold text-marino-900">{{ $numero }}</p>
                    <p class="mt-1 text-sm text-slate-500">{{ $etiqueta }}</p>
                </div>
            @endforeach
        </div>
    @endif

    <div class="tarjeta">
        <h2 class="tarjeta-titulo">Accesos rápidos</h2>
        <div class="flex flex-wrap gap-3">
            @foreach ($accesos as [$texto, $enlace])
                <a href="{{ $enlace }}" class="{{ $loop->first ? 'btn-primario' : 'btn-secundario' }}">{{ $texto }}</a>
            @endforeach
        </div>
        <p class="mt-4 text-sm text-slate-500">Rol: {{ $usuario->rol->nombre }}</p>
    </div>
</x-app-layout>
