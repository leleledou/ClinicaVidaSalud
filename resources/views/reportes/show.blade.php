{{-- CU40 Generar reportes (RF-41) pasos 2 y 3: filtros del reporte, columnas (casillas) y orden; tabla paginada (25)
     con totales y botones «Exportar PDF» y «Exportar Excel/CSV» que conservan los filtros (paso 4).
     A1: filtros no válidos → mensaje y valores por defecto. --}}
@php
    $consulta = request()->except('page');
    $f = $filtros;
    $todosLosErrores = $errores->merge($errors->getBag('default'));
@endphp

<x-app-layout>
    <x-slot name="header">
        <x-encabezado ruta="Gestión / Reportes" :titulo="'Reporte de ' . mb_strtolower($titulo)" />
    </x-slot>

    <div class="space-y-6">
        @if ($todosLosErrores->isNotEmpty())
            <div class="alerta-error space-y-1">
                <p class="font-semibold">Revise los filtros (se usaron los valores por defecto):</p>
                @foreach ($todosLosErrores->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        {{-- CU40 paso 2: filtros personalizables --}}
        <form method="GET" action="{{ route('reportes.mostrar', $tipo) }}" class="tarjeta space-y-5">
            <h2 class="tarjeta-titulo !mb-0">Filtros</h2>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @if (in_array($tipo, ['citas', 'atenciones', 'agenda', 'pacientes']))
                    <div>
                        <x-input-label for="desde" :value="$tipo === 'pacientes' ? 'Registrado desde' : 'Desde'" />
                        <x-text-input id="desde" name="desde" type="date" :value="$f['desde'] ?? ''" />
                    </div>
                    <div>
                        <x-input-label for="hasta" :value="$tipo === 'pacientes' ? 'Registrado hasta' : 'Hasta'" />
                        <x-text-input id="hasta" name="hasta" type="date" :value="$f['hasta'] ?? ''" />
                    </div>
                @endif

                @if (in_array($tipo, ['citas', 'atenciones']))
                    <div>
                        <x-input-label for="especialidad_id" value="Especialidad" />
                        <select id="especialidad_id" name="especialidad_id" class="campo">
                            <option value="">Todas</option>
                            @foreach ($opciones['especialidades'] as $id => $nombre)
                                <option value="{{ $id }}" @selected((string) ($f['especialidad_id'] ?? '') === (string) $id)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if (in_array($tipo, ['citas', 'atenciones', 'agenda']))
                    <div>
                        <x-input-label for="medico_id" value="Médico" />
                        <select id="medico_id" name="medico_id" class="campo">
                            <option value="">Todos</option>
                            @foreach ($opciones['medicos'] as $id => $nombre)
                                <option value="{{ $id }}" @selected((string) ($f['medico_id'] ?? '') === (string) $id)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($tipo === 'citas')
                    @foreach (['estado' => ['Estado', $opciones['estados']], 'origen' => ['Origen', $opciones['origenes']], 'canal' => ['Canal', $opciones['canales']]] as $campo => [$etiqueta, $valores])
                        <div>
                            <x-input-label :for="$campo" :value="$etiqueta" />
                            <select id="{{ $campo }}" name="{{ $campo }}" class="campo">
                                <option value="">Todos</option>
                                @foreach ($valores as $valor)
                                    <option value="{{ $valor }}" @selected(($f[$campo] ?? '') === $valor)>{{ $valor }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                @endif

                @if ($tipo === 'pacientes')
                    <div>
                        <x-input-label for="estado" value="Estado" />
                        <select id="estado" name="estado" class="campo">
                            <option value="">Todos</option>
                            @foreach (['ACTIVO' => 'Activo', 'INACTIVO' => 'Inactivo'] as $valor => $nombre)
                                <option value="{{ $valor }}" @selected(($f['estado'] ?? '') === $valor)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="sexo" value="Sexo" />
                        <select id="sexo" name="sexo" class="campo">
                            <option value="">Todos</option>
                            @foreach (['F' => 'Femenino', 'M' => 'Masculino'] as $valor => $nombre)
                                <option value="{{ $valor }}" @selected(($f['sexo'] ?? '') === $valor)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <x-input-label for="edad_min" value="Edad desde" />
                            <x-text-input id="edad_min" name="edad_min" type="number" min="0" max="130" :value="$f['edad_min'] ?? ''" />
                        </div>
                        <div>
                            <x-input-label for="edad_max" value="Edad hasta" />
                            <x-text-input id="edad_max" name="edad_max" type="number" min="0" max="130" :value="$f['edad_max'] ?? ''" />
                        </div>
                    </div>
                    <div>
                        <x-input-label for="seguro" value="Seguro vigente" />
                        <select id="seguro" name="seguro" class="campo">
                            <option value="">Todos</option>
                            <option value="CON" @selected(($f['seguro'] ?? '') === 'CON')>Con seguro</option>
                            <option value="SIN" @selected(($f['seguro'] ?? '') === 'SIN')>Sin seguro</option>
                        </select>
                    </div>
                @endif

                @if ($tipo === 'atenciones')
                    <div>
                        <x-input-label for="cie10" value="CIE-10 (empieza con)" />
                        <x-text-input id="cie10" name="cie10" maxlength="10" :value="$f['cie10'] ?? ''" placeholder="Por ejemplo, J06 o E11" />
                    </div>
                @endif
            </div>

            {{-- CU40 paso 2: columnas a incluir y orden (se aplican igual en pantalla, PDF y CSV) --}}
            <fieldset class="space-y-2">
                <legend class="text-sm font-medium text-slate-700">Columnas</legend>
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    @foreach ($columnas as $clave => [$nombre])
                        <label class="flex items-center gap-2 text-sm text-slate-700">
                            <input type="checkbox" name="columnas[]" value="{{ $clave }}" class="casilla" @checked(in_array($clave, $f['columnas'], true))>
                            {{ $nombre }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <x-input-label for="orden" value="Ordenar por" />
                    <select id="orden" name="orden" class="campo">
                        @foreach ($columnas as $clave => [$nombre])
                            <option value="{{ $clave }}" @selected($f['orden'] === $clave)>{{ $nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="direccion" value="Dirección" />
                    <select id="direccion" name="direccion" class="campo">
                        <option value="asc" @selected($f['direccion'] === 'asc')>Ascendente</option>
                        <option value="desc" @selected($f['direccion'] === 'desc')>Descendente</option>
                    </select>
                </div>
                <div class="flex items-center gap-3">
                    <x-primary-button>Generar</x-primary-button>
                    <a href="{{ route('reportes.mostrar', $tipo) }}" class="btn-secundario">Limpiar</a>
                </div>
            </div>
        </form>

        {{-- CU40 paso 3: totales --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($totales as $grupo => $valores)
                <div class="tarjeta">
                    <h2 class="tarjeta-titulo">{{ $grupo }}</h2>
                    @if ($valores === [])
                        <p class="text-sm text-slate-500">Sin datos.</p>
                    @else
                        <dl class="space-y-1 text-sm">
                            @foreach ($valores as $etiqueta => $valor)
                                <div class="flex justify-between gap-4"><dt class="text-slate-600">{{ $etiqueta }}</dt><dd class="font-semibold text-marino-900">{{ $valor }}</dd></div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- CU40 pasos 3 y 4: tabla y exportación con los mismos filtros --}}
        <div class="tarjeta space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="tarjeta-titulo !mb-0">{{ $titulo }} ({{ $filas->total() }})</h2>
                <div class="flex flex-wrap gap-2">
                    @foreach ($formatos as $formato => $nombre)
                        <a href="{{ route('reportes.exportar', ['tipo' => $tipo] + $consulta + ['formato' => $formato]) }}" class="btn-secundario btn-chico">Exportar {{ $nombre }}</a>
                    @endforeach
                </div>
            </div>

            <div class="tabla-contenedor">
                <table class="tabla">
                    <thead>
                        <tr>
                            @foreach ($f['columnas'] as $clave)
                                <th>{{ $columnas[$clave][0] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($filasTexto as $fila)
                            <tr>
                                @foreach ($fila as $valor)
                                    <td>{{ $valor }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td colspan="{{ count($f['columnas']) }}" class="py-8 text-center text-slate-500">No hay datos con esos filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <x-paginacion :paginador="$filas" />
        </div>
    </div>
</x-app-layout>
