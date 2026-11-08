// CU39 Ejecutar comando de voz (RF-40) pasos 1, 2, 7 y 8: panel de voz del layout (solo con voz.usar).
// Paso 1: captura con la Web Speech API del navegador (SpeechRecognition / webkitSpeechRecognition, es-ES), sin
// librerías; si el navegador no la tiene, el mismo panel permite escribir el comando. Paso 2: envía la
// transcripción a POST /voz/interpretar. Paso 7: muestra la confirmación. Paso 8: «Confirmar» envía el token a
// POST /voz/ejecutar y abre la pantalla de destino. A6: «Cancelar» no ejecuta nada.

const panel = document.querySelector('[data-voz-panel]');

if (panel) {
    const boton = document.querySelector('[data-voz-abrir]');
    const texto = panel.querySelector('[data-voz-texto]');
    const estado = panel.querySelector('[data-voz-estado]');
    const escuchar = panel.querySelector('[data-voz-escuchar]');
    const interpretar = panel.querySelector('[data-voz-interpretar]');
    const confirmacion = panel.querySelector('[data-voz-confirmacion]');
    const mensajeConfirmacion = panel.querySelector('[data-voz-mensaje]');
    const confirmar = panel.querySelector('[data-voz-confirmar]');
    const cancelar = panel.querySelectorAll('[data-voz-cancelar]');
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;
    let pendiente = null;
    let reconocimiento = null;

    const mostrarEstado = (mensaje, error = false) => {
        estado.textContent = mensaje;
        estado.classList.toggle('text-red-700', error);
        estado.classList.toggle('text-slate-500', !error);
    };

    const ocupado = (si) => {
        [interpretar, confirmar, escuchar].forEach((b) => { if (b) b.disabled = si; });
    };

    const limpiarConfirmacion = () => {
        pendiente = null;
        confirmacion.hidden = true;
        mensajeConfirmacion.textContent = '';
    };

    const abrir = (abierto) => {
        panel.hidden = !abierto;
        boton.setAttribute('aria-expanded', String(abierto));
        if (abierto) {
            texto.focus();
        } else {
            reconocimiento?.abort();
            limpiarConfirmacion();
        }
    };

    // Llamada JSON con el token CSRF; devuelve [respuesta, datos].
    const enviar = async (url, cuerpo) => {
        const respuesta = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify(cuerpo),
        });
        let datos = {};
        try { datos = await respuesta.json(); } catch { datos = {}; }
        return [respuesta, datos];
    };

    // Primer mensaje de error de la respuesta (clave «operacion» o de validación).
    const primerError = (respuesta, datos) => {
        if (respuesta.status === 429) return 'Demasiados comandos seguidos. Espere un minuto.';
        if (respuesta.status === 403) return 'No tiene permiso para ese comando.';
        if (respuesta.status === 419 || respuesta.status === 401) return 'Su sesión expiró. Vuelva a iniciar sesión.';
        const errores = Object.values(datos.errors ?? {}).flat();
        return errores[0] ?? 'No se pudo procesar el comando. Inténtelo otra vez.';
    };

    // CU39 paso 2: interpretar el texto (dicho o escrito).
    const pedirInterpretacion = async () => {
        const comando = texto.value.trim();
        limpiarConfirmacion();
        if (comando === '') {
            mostrarEstado('Diga o escriba un comando.', true);
            return;
        }
        ocupado(true);
        mostrarEstado('Interpretando…');
        try {
            const [respuesta, datos] = await enviar(panel.dataset.urlInterpretar, { texto: comando });
            if (!respuesta.ok) {
                mostrarEstado(primerError(respuesta, datos), true);
                return;
            }
            // CU39 paso 7: confirmación legible.
            pendiente = datos.token;
            mensajeConfirmacion.textContent = datos.confirmacion;
            confirmacion.hidden = false;
            mostrarEstado('');
            confirmar.focus();
        } catch {
            mostrarEstado('No hay conexión. Inténtelo otra vez.', true);
        } finally {
            ocupado(false);
        }
    };

    // CU39 paso 8: ejecutar lo confirmado.
    const ejecutar = async () => {
        if (!pendiente) return;
        ocupado(true);
        mostrarEstado('Ejecutando…');
        try {
            const [respuesta, datos] = await enviar(panel.dataset.urlEjecutar, { token: pendiente });
            limpiarConfirmacion();
            if (!respuesta.ok) {
                mostrarEstado(primerError(respuesta, datos), true);
                return;
            }
            window.location.href = datos.url;
        } catch {
            mostrarEstado('No hay conexión. Inténtelo otra vez.', true);
        } finally {
            ocupado(false);
        }
    };

    // CU39 paso 1: reconocimiento de voz del navegador (si existe).
    if (Reconocimiento) {
        reconocimiento = new Reconocimiento();
        reconocimiento.lang = 'es-ES';
        reconocimiento.interimResults = true;
        reconocimiento.maxAlternatives = 1;
        reconocimiento.onresult = (evento) => {
            const transcripcion = Array.from(evento.results).map((r) => r[0].transcript).join(' ');
            texto.value = transcripcion;
            if (evento.results[evento.results.length - 1].isFinal) {
                pedirInterpretacion();
            }
        };
        reconocimiento.onstart = () => { mostrarEstado('Escuchando… hable ahora.'); escuchar.classList.add('ring-2', 'ring-red-500'); };
        reconocimiento.onend = () => { escuchar.classList.remove('ring-2', 'ring-red-500'); };
        reconocimiento.onerror = (evento) => {
            mostrarEstado(evento.error === 'not-allowed'
                ? 'El navegador no tiene permiso para usar el micrófono. Puede escribir el comando.'
                : 'No se pudo escuchar. Puede escribir el comando.', true);
        };
        escuchar.addEventListener('click', () => {
            limpiarConfirmacion();
            texto.value = '';
            try { reconocimiento.start(); } catch { /* ya estaba escuchando */ }
        });
    } else {
        // CU39 paso 1: sin Web Speech API, solo se escribe.
        escuchar.hidden = true;
        mostrarEstado('Este navegador no reconoce la voz: escriba el comando.');
    }

    boton.addEventListener('click', () => abrir(panel.hidden));
    interpretar.addEventListener('click', pedirInterpretacion);
    texto.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); pedirInterpretacion(); }
    });
    confirmar.addEventListener('click', ejecutar);
    // CU39 A6: cancelar no ejecuta nada (la interpretación vence en la sesión).
    cancelar.forEach((b) => b.addEventListener('click', () => { limpiarConfirmacion(); mostrarEstado('Comando cancelado: no se ejecutó nada.'); }));
    panel.querySelector('[data-voz-cerrar]').addEventListener('click', () => abrir(false));
}
