/*
 * Formularios de médico (CU07) y paciente (CU10): datos de la persona, botón «Buscar» por
 * documento y validación en el cliente. El servidor vuelve a validar lo mismo.
 */

// Mismas reglas que Persona::normalizarDocumento(): mayúsculas, sin espacios, puntos ni guiones.
export function normalizarDocumento(valor) {
    return (valor ?? '').toUpperCase().replace(/[\s.\-]+/g, '');
}

// Años cumplidos a hoy de una fecha AAAA-MM-DD (null si no es válida).
export function edadEnAnios(fecha) {
    if (! /^\d{4}-\d{2}-\d{2}$/.test(fecha ?? '')) return null;
    const nacimiento = new Date(fecha + 'T00:00:00');
    if (isNaN(nacimiento)) return null;
    const hoy = new Date();
    let edad = hoy.getFullYear() - nacimiento.getFullYear();
    const mes = hoy.getMonth() - nacimiento.getMonth();
    if (mes < 0 || (mes === 0 && hoy.getDate() < nacimiento.getDate())) edad--;
    return edad;
}

// Muestra cada mensaje junto a su campo (<p class="js-error" data-campo="...">). Devuelve true si no hay errores.
export function mostrarErrores(formulario, errores) {
    formulario.querySelectorAll('.js-error').forEach((p) => { p.hidden = true; p.textContent = ''; });
    Object.entries(errores).forEach(([campo, mensaje]) => {
        const p = formulario.querySelector('.js-error[data-campo="' + campo + '"]');
        if (p) { p.textContent = mensaje; p.hidden = false; }
    });
    const primero = formulario.querySelector('.js-error:not([hidden])');
    if (primero) primero.scrollIntoView({ behavior: 'smooth', block: 'center' });
    return Object.keys(errores).length === 0;
}

// Fecha local AAAA-MM-DD (toISOString daría la fecha en UTC).
function hoyLocal() {
    const ahora = new Date();
    return ahora.getFullYear() + '-' + String(ahora.getMonth() + 1).padStart(2, '0') + '-' + String(ahora.getDate()).padStart(2, '0');
}

// Datos personales (CU07 paso 4 / CU10 pasos 4 a 6 / CU04 paso 4).
// perfil: 'medico' (documento, mayor de edad y correo obligatorios), 'personal' (documento y mayor
// de edad, correo opcional: cuentas del personal en CU04) o 'paciente' (un menor puede no tener número).
export function erroresPersona(datos, perfil, edadMayoria) {
    const errores = {};
    const texto = (campo) => String(datos[campo] ?? '').trim();
    const edad = edadEnAnios(texto('fecha_nacimiento'));
    const hoy = hoyLocal();
    const esPersonal = perfil === 'medico' || perfil === 'personal';

    if (esPersonal) {
        // CU07 paso 4 / CU04 paso 4: para el personal el documento es obligatorio.
        if (texto('tipo_documento') === '') errores.tipo_documento = 'El campo Tipo de documento es obligatorio.';
        if (normalizarDocumento(texto('numero_documento')) === '') errores.numero_documento = 'El campo Número de documento es obligatorio.';
    } else {
        // CU10: si hay número debe haber tipo; solo un menor puede omitir el número.
        if (normalizarDocumento(texto('numero_documento')) !== '' && texto('tipo_documento') === '') {
            errores.tipo_documento = 'Indique el tipo de documento.';
        }
        if (normalizarDocumento(texto('numero_documento')) === '' && edad !== null && edad >= edadMayoria) {
            errores.numero_documento = 'Solo un menor de edad puede registrarse sin número de documento';
        }
    }

    if (texto('nombres') === '') errores.nombres = 'El campo Nombres es obligatorio.';
    if (texto('apellido_paterno') === '') errores.apellido_paterno = 'El campo Apellido paterno es obligatorio.';

    if (texto('fecha_nacimiento') === '') {
        errores.fecha_nacimiento = 'El campo Fecha de nacimiento es obligatorio.';
    } else if (edad === null) {
        errores.fecha_nacimiento = 'La fecha de nacimiento no es válida.';
    } else if (texto('fecha_nacimiento') > hoy) {
        errores.fecha_nacimiento = 'La fecha de nacimiento no puede ser futura.';
    } else if (perfil === 'medico' && edad < edadMayoria) {
        errores.fecha_nacimiento = 'El médico debe ser mayor de edad (' + edadMayoria + ' años o más).';
    } else if (perfil === 'personal' && edad < edadMayoria) {
        errores.fecha_nacimiento = 'El personal de la clínica debe ser mayor de edad (' + edadMayoria + ' años o más).';
    }

    if (texto('sexo') === '') errores.sexo = 'El campo Sexo es obligatorio.';

    if (texto('telefono') === '') {
        errores.telefono = 'El campo Teléfono es obligatorio.';
    } else if (! /^[0-9+\-\s]{7,20}$/.test(texto('telefono'))) {
        errores.telefono = 'El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).';
    }

    if (texto('correo') === '') {
        if (perfil === 'medico') errores.correo = 'El campo Correo es obligatorio.';
    } else if (! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(texto('correo'))) {
        errores.correo = 'El correo no tiene un formato válido.';
    }

    return errores;
}

/*
 * Componente Alpine del formulario (lista + formulario en la misma página).
 * config: { vacio, inicial, abierto, perfil ('medico' | 'paciente' | 'usuario'), edadMayoria,
 *           urlBuscar, urlFichaPaciente, roles (solo usuario: { id: nombre }) }
 */
export function formularioPersona(config) {
    return {
        abierto: config.abierto,
        form: {},
        // Resultado del botón «Buscar».
        mensaje: '',
        tipoMensaje: 'info',
        enlaceFicha: null,
        // Documento de la persona encontrada: mientras no cambie, sus datos no se editan.
        documentoEncontrado: null,

        init() {
            this.form = config.inicial ?? structuredClone(config.vacio);
        },

        // true si se encontró a la persona y el documento no cambió desde la búsqueda.
        get personaReutilizada() {
            return this.documentoEncontrado !== null && this.documentoEncontrado === this.claveDocumento();
        },

        claveDocumento() {
            return [this.form.tipo_documento, normalizarDocumento(this.form.numero_documento), normalizarDocumento(this.form.complemento)].join('|');
        },

        // CU04 paso 5: nombre del rol elegido (para mostrar los campos de ese rol).
        get rolElegido() {
            return (config.roles ?? {})[this.form.rol_id] ?? '';
        },

        // CU04: perfiles de la persona (de la búsqueda o de la cuenta que se modifica).
        get perfilesPersona() {
            return this.form.id || this.personaReutilizada ? (this.form.perfiles ?? null) : null;
        },

        nuevo() {
            this.form = structuredClone(config.vacio);
            this.mostrar();
        },

        // Modificar: los null se muestran como campos vacíos.
        editar(datos) {
            this.form = Object.fromEntries(Object.entries(datos).map(([clave, valor]) => [clave, valor ?? '']));
            this.mostrar();
        },

        mostrar() {
            this.abierto = true;
            this.mensaje = '';
            this.enlaceFicha = null;
            this.documentoEncontrado = null;
            mostrarErrores(this.$refs.formulario, {});
            this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
        },

        // CU07 paso 4 / CU10 paso 2: buscar la persona por documento (PersonaController@buscar).
        async buscar() {
            this.enlaceFicha = null;
            this.documentoEncontrado = null;

            if (this.form.tipo_documento === '' || normalizarDocumento(this.form.numero_documento) === '') {
                this.avisar('Indique el tipo y el número de documento para buscar.', 'error');
                return;
            }

            const parametros = new URLSearchParams({
                tipo: this.form.tipo_documento,
                numero: this.form.numero_documento,
                complemento: this.form.complemento ?? '',
            });

            let datos;
            try {
                const respuesta = await fetch(config.urlBuscar + '?' + parametros, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (! respuesta.ok) throw new Error();
                datos = await respuesta.json();
            } catch {
                this.avisar('No se pudo buscar la persona. Intente de nuevo.', 'error');
                return;
            }

            if (! datos.existe) {
                this.avisar('No hay ninguna persona registrada con ese documento: complete sus datos.', 'info');
                return;
            }

            // La persona ya tiene el perfil que se quiere registrar.
            if (config.perfil === 'medico' && datos.medico) {
                this.avisar('Esa persona ya está registrada como médico (' + datos.medico.codigo + ')', 'error');
                return;
            }
            if (config.perfil === 'paciente' && datos.paciente) {
                this.avisar('Ya existe una persona con ese documento (' + datos.paciente.codigo + ').', 'error');
                this.enlaceFicha = config.urlFichaPaciente.replace('__ID__', datos.paciente.id);
                return;
            }
            // CU04 paso 4: una persona tiene como máximo una cuenta de acceso.
            if (config.perfil === 'usuario' && datos.usuario) {
                this.avisar('Esa persona ya tiene una cuenta de acceso (usuario «' + datos.usuario.nombre_usuario + '»)', 'error');
                return;
            }

            // CU10 A1 / CU07 paso 4 / CU04 paso 4: se reutilizan los datos de la persona encontrada.
            Object.entries(datos.persona).forEach(([clave, valor]) => {
                if (clave !== 'id' && clave in this.form) this.form[clave] = valor ?? '';
            });
            this.documentoEncontrado = this.claveDocumento();

            // CU04 paso 5: perfiles que ya tiene (deciden qué campos del rol se piden y RN25).
            if (config.perfil === 'usuario') {
                this.form.perfiles = { medico: datos.medico, paciente: datos.paciente, recepcionista: datos.recepcionista, administrador: datos.administrador };
                const lista = ['medico', 'paciente', 'recepcionista', 'administrador']
                    .filter((clave) => datos[clave])
                    .map((clave) => datos[clave].codigo + ' (' + datos[clave].estado + ')');
                this.avisar('Persona ya registrada: se reutilizarán sus datos' + (lista.length ? '. Perfiles: ' + lista.join(', ') : ''), 'ok');
                return;
            }

            this.avisar('Persona ya registrada: se reutilizarán sus datos', 'ok');
        },

        avisar(texto, tipo) {
            this.mensaje = texto;
            this.tipoMensaje = tipo;
        },
    };
}

// CU07 pasos 4 a 7: validación del formulario de médico.
export function validarMedico(formulario, edadMayoria, esModificacion) {
    const datos = Object.fromEntries(new FormData(formulario));
    const errores = erroresPersona(datos, 'medico', edadMayoria);

    // Al modificar, el documento no se envía (no se puede cambiar).
    if (esModificacion) {
        delete errores.tipo_documento;
        delete errores.numero_documento;
    }

    if (String(datos.registro_profesional ?? '').trim() === '') {
        errores.registro_profesional = 'El campo Registro profesional es obligatorio.';
    }

    const elegidas = [...formulario.querySelectorAll('input[name="especialidades[]"]:checked')].map((casilla) => casilla.value);
    if (elegidas.length === 0) {
        errores.especialidades = 'Todo médico debe ejercer al menos una especialidad activa';
    } else if (! datos.principal) {
        errores.especialidades = 'Marque cuál de las especialidades elegidas es la principal.';
    } else if (! elegidas.includes(datos.principal)) {
        errores.especialidades = 'La especialidad principal debe estar entre las elegidas.';
    }

    return mostrarErrores(formulario, errores);
}

// CU10 pasos 4 a 6: validación del formulario de paciente.
export function validarPaciente(formulario, edadMayoria) {
    const datos = Object.fromEntries(new FormData(formulario));
    return mostrarErrores(formulario, erroresPersona(datos, 'paciente', edadMayoria));
}

// CU04 pasos 4 a 7: validación del formulario de usuario.
// componente: el estado Alpine (rolElegido, perfilesPersona, form.id, personaReutilizada).
export function validarUsuario(formulario, edadMayoria, componente) {
    const datos = Object.fromEntries(new FormData(formulario));
    const texto = (campo) => String(datos[campo] ?? '').trim();
    const rol = componente.rolElegido;
    const perfiles = componente.perfilesPersona ?? {};
    const esPersonal = ['ADMINISTRADOR', 'RECEPCIONISTA', 'MEDICO'].includes(rol);

    // CU04 paso 4: datos de la persona solo al crear la cuenta (al modificar no se envían cambios).
    let errores = {};
    if (! componente.form.id) {
        errores = erroresPersona(datos, esPersonal ? 'personal' : 'paciente', edadMayoria);
    }

    // CU04 paso 5: rol y campos del perfil que se crea con él.
    if (texto('rol_id') === '') {
        errores.rol_id = 'Elija el rol de la cuenta.';
    } else if (rol === 'MEDICO' && perfiles.medico?.estado !== 'ACTIVO') {
        errores.rol_id = 'El rol MEDICO exige que la persona tenga el perfil de médico ACTIVO';
    } else if (rol === 'PACIENTE' && perfiles.paciente?.estado !== 'ACTIVO') {
        errores.rol_id = 'El rol PACIENTE exige que la persona tenga el perfil de paciente ACTIVO';
    } else if (rol === 'RECEPCIONISTA' && perfiles.recepcionista && perfiles.recepcionista.estado !== 'ACTIVO') {
        errores.rol_id = 'El rol RECEPCIONISTA exige que la persona tenga el perfil de recepcionista ACTIVO';
    } else if (rol === 'ADMINISTRADOR' && perfiles.administrador && perfiles.administrador.estado !== 'ACTIVO') {
        errores.rol_id = 'El rol ADMINISTRADOR exige que la persona tenga el perfil de administrador ACTIVO';
    }

    if (rol === 'RECEPCIONISTA' && ! perfiles.recepcionista) {
        if (texto('turno') === '') errores.turno = 'Elija el turno de la recepcionista.';
        if (texto('fecha_ingreso') === '') {
            errores.fecha_ingreso = 'El campo Fecha de ingreso es obligatorio.';
        } else if (texto('fecha_ingreso') > hoyLocal()) {
            errores.fecha_ingreso = 'La fecha de ingreso no puede ser futura.';
        }
    }
    if (rol === 'ADMINISTRADOR' && ! perfiles.administrador && texto('cargo') === '') {
        errores.cargo = 'El campo Cargo es obligatorio.';
    }

    // CU04 paso 6: nombre de usuario y correo de acceso.
    if (texto('nombre_usuario') === '') {
        errores.nombre_usuario = 'El campo Nombre de usuario es obligatorio.';
    } else if (! /^[A-Za-z0-9._-]{3,50}$/.test(texto('nombre_usuario'))) {
        errores.nombre_usuario = 'El nombre de usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.';
    }
    if (texto('correo_acceso') === '') {
        errores.correo_acceso = 'El campo Correo de acceso es obligatorio.';
    } else if (! /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(texto('correo_acceso'))) {
        errores.correo_acceso = 'El correo de acceso no tiene un formato válido.';
    }

    return mostrarErrores(formulario, errores);
}

// CU05 paso 6: validación del formulario de rol (nombre obligatorio).
export function validarRol(formulario) {
    const errores = {};
    if (String(formulario.elements.nombre.value ?? '').trim() === '') {
        errores.nombre = 'El campo Nombre es obligatorio.';
    }
    return mostrarErrores(formulario, errores);
}
