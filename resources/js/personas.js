/*
 * Formularios de médico (CU07), paciente (CU10), usuario (CU04), vínculo (CU13) y autorregistro
 * (CU12): datos de la persona, botón «Buscar» por documento y validación en el cliente.
 * El servidor vuelve a validar lo mismo.
 */

import { revisarPassword } from './password';

// Mismos formatos que Persona::FORMATO_TELEFONO y Usuario::FORMATO_NOMBRE_USUARIO.
const FORMATO_TELEFONO = /^[0-9+\-\s]{7,20}$/;
const FORMATO_CORREO = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const FORMATO_NOMBRE_USUARIO = /^[A-Za-z0-9._-]{3,50}$/;

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

// Datos personales (CU07 paso 4 / CU10 pasos 4 a 6 / CU04 paso 4 / CU13 paso 3 / CU12 paso 5).
// perfil: 'medico' (documento, mayor de edad y correo obligatorios), 'personal' (documento y mayor
// de edad, correo opcional: cuentas del personal en CU04), 'paciente' (un menor puede no tener número),
// 'contacto' (CU13: documento opcional; el responsable legal se revisa aparte) o 'registro'
// (CU12: documento, mayor de edad y correo obligatorios).
export function erroresPersona(datos, perfil, edadMayoria) {
    const errores = {};
    const texto = (campo) => String(datos[campo] ?? '').trim();
    const edad = edadEnAnios(texto('fecha_nacimiento'));
    const hoy = hoyLocal();
    const esPersonal = perfil === 'medico' || perfil === 'personal';

    if (esPersonal || perfil === 'registro') {
        // CU07 paso 4 / CU04 paso 4 / CU12 paso 2: para el personal y el autorregistro el documento es obligatorio.
        if (texto('tipo_documento') === '') errores.tipo_documento = 'El campo Tipo de documento es obligatorio.';
        if (normalizarDocumento(texto('numero_documento')) === '') errores.numero_documento = 'El campo Número de documento es obligatorio.';
    } else {
        // CU10: si hay número debe haber tipo; solo un menor puede omitir el número.
        if (normalizarDocumento(texto('numero_documento')) !== '' && texto('tipo_documento') === '') {
            errores.tipo_documento = 'Indique el tipo de documento.';
        }
        if (perfil !== 'contacto' && normalizarDocumento(texto('numero_documento')) === '' && edad !== null && edad >= edadMayoria) {
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
    } else if (perfil === 'registro' && edad < edadMayoria) {
        errores.fecha_nacimiento = 'Debes ser mayor de edad. Si eres menor, tu responsable legal debe registrarte en recepción.';
    }

    if (texto('sexo') === '') errores.sexo = 'El campo Sexo es obligatorio.';

    if (texto('telefono') === '') {
        errores.telefono = 'El campo Teléfono es obligatorio.';
    } else if (! FORMATO_TELEFONO.test(texto('telefono'))) {
        errores.telefono = 'El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).';
    }

    if (texto('correo') === '') {
        if (perfil === 'medico' || perfil === 'registro') errores.correo = 'El campo Correo es obligatorio.';
    } else if (! FORMATO_CORREO.test(texto('correo'))) {
        errores.correo = 'El correo no tiene un formato válido.';
    }

    return errores;
}

/*
 * Componente Alpine del formulario (lista + formulario en la misma página).
 * config: { vacio, inicial, abierto, perfil ('medico' | 'paciente' | 'usuario' | 'vinculo'), edadMayoria,
 *           urlBuscar, urlFichaPaciente, roles (solo usuario: { id: nombre }),
 *           pacienteId (solo vínculo: el paciente al que se vincula la persona) }
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
        // CU13 A1: al paciente la búsqueda solo le devuelve el nombre (sin datos de contacto de terceros).
        datosReducidos: false,

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
            this.datosReducidos = false;
            mostrarErrores(this.$refs.formulario, {});
            this.$nextTick(() => this.$refs.formulario.scrollIntoView({ behavior: 'smooth' }));
        },

        // CU07 paso 4 / CU10 paso 2: buscar la persona por documento (PersonaController@buscar).
        async buscar() {
            this.enlaceFicha = null;
            this.documentoEncontrado = null;
            this.datosReducidos = false;

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
            // CU13 paso 6: la persona vinculada no puede ser el propio paciente.
            if (config.perfil === 'vinculo' && (datos.propio || (datos.paciente && String(datos.paciente.id) === String(config.pacienteId)))) {
                this.avisar('La persona vinculada no puede ser el propio paciente', 'error');
                return;
            }
            // CU13 A1: el paciente solo ve el nombre; el servidor usará los datos que tiene la clínica.
            if (datos.reducida) {
                ['nombres', 'apellido_paterno', 'apellido_materno', 'fecha_nacimiento', 'sexo', 'telefono', 'correo', 'direccion'].forEach((campo) => { this.form[campo] = ''; });
                this.form.nombres = datos.persona.nombres;
                this.form.apellido_paterno = datos.persona.apellido_paterno;
                this.documentoEncontrado = this.claveDocumento();
                this.datosReducidos = true;
                this.avisar('Persona ya registrada (' + datos.persona.nombres + ' ' + datos.persona.apellido_paterno + '): se vinculará con los datos que tiene la clínica', 'ok');
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
    } else if (! FORMATO_NOMBRE_USUARIO.test(texto('nombre_usuario'))) {
        errores.nombre_usuario = 'El nombre de usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.';
    }
    if (texto('correo_acceso') === '') {
        errores.correo_acceso = 'El campo Correo de acceso es obligatorio.';
    } else if (! FORMATO_CORREO.test(texto('correo_acceso'))) {
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

// CU13 pasos 3 a 6: validación del formulario de vínculo.
// componente: el estado Alpine (form, personaReutilizada, datosReducidos).
export function validarVinculo(formulario, edadMayoria, componente) {
    const datos = Object.fromEntries(new FormData(formulario));
    const form = componente.form;
    const esModificacion = Boolean(form.id);

    // CU13 paso 3: datos de una persona nueva (si se reutiliza una ya registrada, no se editan).
    let errores = {};
    if (! esModificacion && ! componente.personaReutilizada) {
        errores = erroresPersona(datos, 'contacto', edadMayoria);
    }

    // CU13 paso 4: parentesco y al menos un tipo.
    if (String(datos.parentesco ?? '') === '') errores.parentesco = 'Elija el parentesco.';
    if (! form.es_contacto_emergencia && ! form.es_responsable_legal) {
        errores.tipos = 'Marque al menos un tipo de vínculo: contacto de emergencia o responsable legal.';
    }

    // CU13 paso 6: un responsable legal debe ser mayor de edad y tener documento con número.
    // (Al modificar y con datos reducidos lo revisa el servidor con los datos registrados.)
    if (! esModificacion && ! componente.datosReducidos && form.es_responsable_legal) {
        const edad = edadEnAnios(String(form.fecha_nacimiento ?? ''));
        if (normalizarDocumento(form.numero_documento) === '' || (edad !== null && edad < edadMayoria)) {
            errores.es_responsable_legal = 'El responsable legal debe ser mayor de edad y tener documento de identidad';
        }
    }

    return mostrarErrores(formulario, errores);
}

// CU12 pasos 2 y 5: validación del formulario de autorregistro.
export function validarRegistro(formulario, edadMayoria) {
    const datos = Object.fromEntries(new FormData(formulario));
    const errores = erroresPersona(datos, 'registro', edadMayoria);
    const usuario = String(datos.nombre_usuario ?? '').trim();
    const password = String(datos.password ?? '');

    if (usuario === '') {
        errores.nombre_usuario = 'El campo Nombre de usuario es obligatorio.';
    } else if (! FORMATO_NOMBRE_USUARIO.test(usuario)) {
        errores.nombre_usuario = 'El nombre de usuario debe tener de 3 a 50 caracteres: letras, números, punto, guion o guion bajo.';
    }

    // CU12 paso 5: política de CU03 y confirmación igual.
    if (password === '') {
        errores.password = 'El campo Contraseña es obligatorio.';
    } else if (Object.values(revisarPassword(password)).includes(false)) {
        errores.password = 'Faltan requisitos de la contraseña (marcados en rojo).';
    }
    if (password !== '' && password !== String(datos.password_confirmation ?? '')) {
        errores.password_confirmation = 'La confirmación no coincide con la contraseña.';
    }

    if (! formulario.elements.privacidad.checked) {
        errores.privacidad = 'Debes aceptar las políticas de privacidad.';
    }

    return mostrarErrores(formulario, errores);
}