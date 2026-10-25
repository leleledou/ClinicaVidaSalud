/*
 * CU14 Consultar disponibilidad de fichas, CU15 Programar cita, CU16 Reprogramar cita, CU17 Cancelar
 * cita, CU18 Registrar asistencia, CU19 Consultar agenda y CU20 Consultar historial: validación en el
 * cliente y componentes Alpine de los formularios. El servidor vuelve a validar todo.
 */

import { mostrarErrores } from './personas';

// CU15 paso 3 / CU16 paso 3 (RF06): SOLO las fichas disponibles del médico en la especialidad y la fecha.
// Respuesta: { fichas: [...], proxima: { fecha, texto } | null } (mejora b: el próximo día disponible).
async function pedirFichas(url, medicoId, especialidadId, fecha) {
    const parametros = new URLSearchParams({ medico_id: medicoId, especialidad_id: especialidadId, fecha });
    const respuesta = await fetch(url + '?' + parametros, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    });
    if (! respuesta.ok) throw new Error();
    return respuesta.json();
}

// Fecha local AAAA-MM-DD a partir de un Date.
function aTexto(fecha) {
    return fecha.getFullYear() + '-' + String(fecha.getMonth() + 1).padStart(2, '0') + '-' + String(fecha.getDate()).padStart(2, '0');
}

// CU14 paso 2: especialidad obligatoria; desde no anterior a hoy; hasta no anterior a desde; rango máximo.
export function validarFiltrosFichas(formulario, maxDias) {
    const datos = Object.fromEntries(new FormData(formulario));
    const errores = {};
    const hoy = aTexto(new Date());

    if (! datos.especialidad_id) errores.especialidad_id = 'Elija la especialidad.';

    if (! datos.desde) {
        errores.desde = 'Indique la fecha desde.';
    } else if (datos.desde < hoy) {
        errores.desde = 'La fecha desde no puede ser anterior a hoy.';
    }

    if (! datos.hasta) {
        errores.hasta = 'Indique la fecha hasta.';
    } else if (datos.desde && datos.hasta < datos.desde) {
        errores.hasta = 'La fecha hasta no puede ser anterior a la fecha desde.';
    } else if (datos.desde) {
        const dias = Math.round((new Date(datos.hasta + 'T00:00:00') - new Date(datos.desde + 'T00:00:00')) / 86400000);
        if (dias > maxDias - 1) errores.hasta = 'El rango no puede superar ' + maxDias + ' días.';
    }

    return mostrarErrores(formulario, errores);
}

// CU15 pasos 1 a 5: no se confirma sin paciente, especialidad, médico, fecha (no pasada) y ficha;
// motivo de 500 caracteres como máximo.
export function validarCita(formulario, componente, hoy) {
    const errores = {};

    if (! componente.paciente) errores.paciente_id = 'Elija al paciente.';
    if (! componente.especialidadId) errores.especialidad_id = 'Elija la especialidad.';
    if (! componente.medicoId) errores.medico_id = 'Elija el médico.';

    if (! componente.fecha) {
        errores.fecha = 'Elija la fecha.';
    } else if (componente.fecha < hoy) {
        errores.fecha = 'La fecha no puede ser anterior a hoy.';
    }

    if (! componente.fichaId) errores.ficha_id = 'Elija un horario disponible.';
    if ((componente.motivo ?? '').length > 500) errores.motivo = 'El motivo no puede superar los 500 caracteres.';

    return mostrarErrores(formulario, errores);
}

/*
 * Componente Alpine del formulario de cita (CU15).
 * config: { modo ('RECEPCION' | 'AUTOSERVICIO'), opciones (A1: «Para mí» y menores a cargo),
 *           inicialPaciente, inicial, especialidades (con límites RN15 y sus médicos), urlFichas,
 *           urlPacientes, hoy }
 */
export function formularioCita(config) {
    return {
        modo: config.modo,
        opciones: config.opciones ?? [],
        especialidades: config.especialidades,
        paciente: null,
        // CU15 paso 1: buscador de pacientes (recepción).
        busqueda: '',
        resultados: [],
        mensajeBusqueda: '',
        // CU15 pasos 2 a 4.
        especialidadId: '',
        medicoId: '',
        fecha: '',
        fichas: [],
        fichaId: '',
        cargando: false,
        mensajeFichas: '',
        // CU15 / CU16 mejora b: el próximo día con fichas disponibles ({ fecha, texto } o null).
        proxima: null,
        motivo: '',
        seguroId: '',
        codigoAutorizacion: '',
        // CU15 paso 5: resumen antes de confirmar.
        resumen: false,

        init() {
            const inicial = config.inicial;
            this.paciente = this.modo === 'AUTOSERVICIO'
                ? (this.opciones.find((p) => String(p.id) === String(inicial.paciente_id)) ?? this.opciones[0] ?? null)
                : config.inicialPaciente;
            this.especialidadId = String(inicial.especialidad_id ?? '');
            this.medicoId = String(inicial.medico_id ?? '');
            this.fecha = inicial.fecha ?? config.hoy;
            this.motivo = inicial.motivo ?? '';
            this.seguroId = String(inicial.seguro_paciente_id ?? '');
            this.codigoAutorizacion = inicial.codigo_autorizacion_seguro ?? '';

            // Si la especialidad preseleccionada no admite al paciente, se descarta.
            if (this.especialidadId && ! this.especialidad) {
                this.especialidadId = '';
                this.medicoId = '';
            }
            // CU14 paso 6 / CU15 paso 8: se recargan las fichas libres y se marca la elegida si sigue libre.
            this.cargarFichas(String(inicial.ficha_id ?? ''));
        },

        // CU15 paso 2 / RN15: especialidades que admiten la edad y el sexo del paciente.
        get especialidadesPermitidas() {
            if (! this.paciente) return [];
            const { edad, sexo } = this.paciente;
            return this.especialidades.filter((e) =>
                (e.edad_minima === null || edad >= e.edad_minima)
                && (e.edad_maxima === null || edad <= e.edad_maxima)
                && (e.sexo_permitido === null || e.sexo_permitido === sexo));
        },
        get especialidad() {
            return this.especialidadesPermitidas.find((e) => String(e.id) === this.especialidadId) ?? null;
        },
        // CU15 paso 2: solo los médicos ACTIVOS que ejercen la especialidad.
        get medicos() {
            return this.especialidad?.medicos ?? [];
        },
        get medico() {
            return this.medicos.find((m) => String(m.id) === this.medicoId) ?? null;
        },
        get ficha() {
            return this.fichas.find((f) => String(f.id) === String(this.fichaId)) ?? null;
        },
        get seguro() {
            return (this.paciente?.seguros ?? []).find((s) => String(s.id) === this.seguroId) ?? null;
        },
        // Mejora b: salta al próximo día disponible.
        irAProxima() {
            if (! this.proxima) return;
            this.fecha = this.proxima.fecha;
            this.cargarFichas();
        },

        // CU15 paso 1: buscar pacientes ACTIVOS (mismo criterio que CU11).
        async buscarPacientes() {
            this.resultados = [];
            if (this.busqueda.trim().length < 2) {
                this.mensajeBusqueda = 'Escriba al menos 2 caracteres: documento, código PAC- o nombre.';
                return;
            }
            try {
                const respuesta = await fetch(config.urlPacientes + '?' + new URLSearchParams({ q: this.busqueda.trim() }), {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                });
                if (! respuesta.ok) throw new Error();
                this.resultados = await respuesta.json();
                this.mensajeBusqueda = this.resultados.length ? '' : 'No se encontraron pacientes activos con ese dato.';
            } catch {
                this.mensajeBusqueda = 'No se pudo buscar. Intente de nuevo.';
            }
        },

        elegirPaciente(paciente) {
            this.paciente = paciente ?? null;
            this.resultados = [];
            this.busqueda = '';
            this.seguroId = '';
            this.resumen = false;
            // RN15: si la especialidad elegida no admite al nuevo paciente, se vuelve a elegir.
            if (! this.especialidad) {
                this.especialidadId = '';
                this.medicoId = '';
                this.limpiarFichas();
            }
        },

        cambiarEspecialidad() {
            this.medicoId = '';
            this.limpiarFichas();
        },

        limpiarFichas() {
            this.fichas = [];
            this.fichaId = '';
            this.mensajeFichas = '';
            this.proxima = null;
            this.resumen = false;
        },

        // CU15 paso 3 / RF06: SOLO las fichas disponibles del médico en esa fecha.
        async cargarFichas(preseleccion = '') {
            this.limpiarFichas();
            if (! this.especialidadId || ! this.medicoId || ! this.fecha) return;
            if (this.fecha < config.hoy) {
                this.mensajeFichas = 'La fecha no puede ser anterior a hoy.';
                return;
            }

            this.cargando = true;
            try {
                const datos = await pedirFichas(config.urlFichas, this.medicoId, this.especialidadId, this.fecha);
                this.fichas = datos.fichas;
                this.proxima = datos.proxima;
                if (this.fichas.length === 0) this.mensajeFichas = 'El médico no tiene horarios disponibles ese día.';
                if (preseleccion && this.fichas.some((f) => String(f.id) === preseleccion)) this.fichaId = preseleccion;
            } catch {
                this.mensajeFichas = 'No se pudieron cargar los horarios. Intente de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        // CU15 paso 5: valida y muestra el resumen antes de confirmar.
        revisar() {
            if (validarCita(this.$refs.formulario, this, config.hoy)) {
                this.resumen = true;
                this.$nextTick(() => this.$refs.resumen?.scrollIntoView({ behavior: 'smooth' }));
            }
        },
    };
}

// CU16 paso 4 y A1: no se confirma el cambio sin la fecha (no pasada) y la nueva ficha; en la
// reasignación, además, sin el otro médico. Motivo de 500 caracteres como máximo.
export function validarCambioFicha(formulario, componente, hoy) {
    const errores = {};

    if (componente.reasignar && ! componente.medicoId) errores.medico_id = 'Elija el médico.';
    if (! componente.fecha) {
        errores.fecha = 'Elija la fecha.';
    } else if (componente.fecha < hoy) {
        errores.fecha = 'La fecha no puede ser anterior a hoy.';
    }
    if (! componente.fichaId) errores.ficha_id = 'Elija la nueva ficha.';
    if ((componente.motivo ?? '').length > 500) errores.motivo = 'El motivo no puede superar los 500 caracteres.';

    return mostrarErrores(formulario, errores);
}

// CU17 paso 3: motivo obligatorio (máximo 500 caracteres) y confirmación antes de cancelar.
export function validarCancelacion(formulario, codigo) {
    const motivo = (formulario.querySelector('[name="motivo_cancelacion"]')?.value ?? '').trim();
    const errores = {};

    if (motivo === '') {
        errores.motivo_cancelacion = 'El motivo de cancelación es obligatorio.';
    } else if (motivo.length > 500) {
        errores.motivo_cancelacion = 'El motivo de cancelación no puede superar los 500 caracteres.';
    }

    if (! mostrarErrores(formulario, errores)) return false;
    return window.confirm('¿Cancelar la cita ' + codigo + '? El horario quedará disponible para otro paciente.');
}

/*
 * Componente Alpine de CU16: reprogramar (mismo médico, pasos 3 y 4) o reasignar (otro médico de
 * la misma especialidad, A1). Reutiliza la consulta de fichas disponibles de CU15 (citas.fichas).
 * config: { reasignar (bool), urlFichas, especialidadId, medicoId (fijo al reprogramar), medicos
 *           (A1: los otros médicos), actual (texto de la ficha actual), codigo, inicial, hoy }
 */
export function formularioCambioFicha(config) {
    return {
        reasignar: config.reasignar,
        medicos: config.medicos ?? [],
        medicoId: '',
        fecha: '',
        fichas: [],
        fichaId: '',
        cargando: false,
        mensajeFichas: '',
        // CU15 / CU16 mejora b: el próximo día con fichas disponibles ({ fecha, texto } o null).
        proxima: null,
        motivo: '',
        // CU16 paso 4: resumen «ficha actual → nueva» antes de confirmar.
        resumen: false,

        init() {
            const inicial = config.inicial ?? {};
            this.medicoId = String(config.reasignar ? (inicial.medico_id ?? '') : config.medicoId);
            this.fecha = inicial.fecha ?? '';
            this.motivo = inicial.motivo ?? '';
            if (this.fecha) this.cargarFichas(String(inicial.ficha_id ?? ''));
        },

        get medico() {
            return this.medicos.find((m) => String(m.id) === this.medicoId) ?? null;
        },
        // Mejora a: días y horas en que atiende el médico (el actual o, en A1, el elegido).
        get atiende() {
            return this.reasignar ? (this.medico?.atiende ?? '') : (config.atiende ?? '');
        },
        get ficha() {
            return this.fichas.find((f) => String(f.id) === String(this.fichaId)) ?? null;
        },
        // Mejora b: salta al próximo día disponible.
        irAProxima() {
            if (! this.proxima) return;
            this.fecha = this.proxima.fecha;
            this.cargarFichas();
        },
        // Texto de la nueva ficha para el resumen.
        get textoNueva() {
            if (! this.ficha) return '';
            return this.fecha.split('-').reverse().join('/') + ' ' + this.ficha.hora_inicio + '–' + this.ficha.hora_fin
                + (this.medico ? ' con ' + this.medico.nombre : '');
        },

        limpiarFichas() {
            this.fichas = [];
            this.fichaId = '';
            this.mensajeFichas = '';
            this.proxima = null;
            this.resumen = false;
        },

        // CU16 paso 3: SOLO las fichas disponibles del médico (el mismo, o el elegido en A1) en esa fecha.
        async cargarFichas(preseleccion = '') {
            this.limpiarFichas();
            if (! this.medicoId || ! this.fecha) return;
            if (this.fecha < config.hoy) {
                this.mensajeFichas = 'La fecha no puede ser anterior a hoy.';
                return;
            }

            this.cargando = true;
            try {
                const datos = await pedirFichas(config.urlFichas, this.medicoId, config.especialidadId, this.fecha);
                this.fichas = datos.fichas;
                this.proxima = datos.proxima;
                if (this.fichas.length === 0) this.mensajeFichas = 'El médico no tiene horarios disponibles ese día.';
                if (preseleccion && this.fichas.some((f) => String(f.id) === preseleccion)) this.fichaId = preseleccion;
            } catch {
                this.mensajeFichas = 'No se pudieron cargar los horarios. Intente de nuevo.';
            } finally {
                this.cargando = false;
            }
        },

        // CU16 paso 4: valida y muestra el resumen antes de confirmar.
        revisar() {
            this.resumen = validarCambioFicha(this.$refs.formulario, this, config.hoy);
        },

        // Al enviar: valida otra vez y, en la reasignación, pide confirmación (cancela la cita original).
        enviar(evento) {
            if (! validarCambioFicha(evento.target, this, config.hoy)) {
                evento.preventDefault();
                this.resumen = false;
                return;
            }
            if (this.reasignar && ! window.confirm('¿Reasignar la cita ' + config.codigo + ' a ' + this.textoNueva + '? La cita actual quedará cancelada y se registrará una nueva.')) {
                evento.preventDefault();
            }
        },
    };
}

// Fecha AAAA-MM-DD que existe en el calendario.
function fechaValida(texto) {
    if (! /^\d{4}-\d{2}-\d{2}$/.test(texto)) return false;
    const fecha = new Date(texto + 'T00:00:00');
    return ! Number.isNaN(fecha.getTime()) && aTexto(fecha) === texto;
}

// CU19 paso 3: fecha válida y, para quien no es médico, el médico obligatorio.
export function validarFiltrosAgenda(formulario, requiereMedico) {
    const datos = Object.fromEntries(new FormData(formulario));
    const errores = {};

    if (requiereMedico && ! datos.medico_id) errores.medico_id = 'Elija el médico.';
    if (! datos.fecha) {
        errores.fecha = 'Elija la fecha.';
    } else if (! fechaValida(datos.fecha)) {
        errores.fecha = 'La fecha no es válida.';
    }

    return mostrarErrores(formulario, errores);
}

// CU20 paso 3: fechas válidas (opcionales) y «desde» no posterior a «hasta».
export function validarFiltrosHistorial(formulario) {
    const datos = Object.fromEntries(new FormData(formulario));
    const errores = {};

    if (datos.desde && ! fechaValida(datos.desde)) errores.desde = 'La fecha desde no es válida.';
    if (datos.hasta && ! fechaValida(datos.hasta)) errores.hasta = 'La fecha hasta no es válida.';
    if (! errores.desde && ! errores.hasta && datos.desde && datos.hasta && datos.desde > datos.hasta) {
        errores.hasta = 'La fecha desde no puede ser posterior a la fecha hasta.';
    }

    return mostrarErrores(formulario, errores);
}
