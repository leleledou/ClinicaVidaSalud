// CU37 Enviar recordatorios y notificaciones (RF-38) paso 4: el «push» de RF-38 sin servidor push ni claves.
// Se carga solo en el layout con sesión (layouts/app). Cada 60 s pide GET /notificaciones/nuevas?desde=<último id visto>,
// actualiza el contador del menú (CU38 paso 4) y, si la cuenta tiene notificar_por_push y el navegador dio
// permiso (Notification.requestPermission), muestra cada aviso con new Notification(titulo, { body: mensaje }).
// El último id visto se guarda en localStorage (por usuario).

const INTERVALO_MS = 60000;
const cuerpo = document.body;
const url = cuerpo.dataset.notificacionesUrl;
const clave = `notificaciones.ultimoId.${cuerpo.dataset.notificacionesUsuario}`;
const pushActivado = cuerpo.dataset.notificacionesPush === '1';
const soportado = 'Notification' in window;
let temporizador = null;

// localStorage puede no estar disponible (navegación privada o bloqueado): entonces se empieza de cero.
function leerUltimo() {
    try {
        return parseInt(localStorage.getItem(clave) ?? '0', 10) || 0;
    } catch {
        return 0;
    }
}

function guardarUltimo(id) {
    try {
        localStorage.setItem(clave, String(id));
    } catch {
        // Sin almacenamiento: el próximo pedido vuelve a empezar desde cero (A5: sin repetir los viejos).
    }
}

// CU38 paso 4: número de no leídas en el menú (oculto si es 0).
function actualizarContador(noLeidas) {
    document.querySelectorAll('[data-contador-notificaciones]').forEach((contador) => {
        contador.textContent = noLeidas > 99 ? '99+' : String(noLeidas);
        contador.setAttribute('aria-label', `${noLeidas} sin leer`);
        contador.classList.toggle('hidden', noLeidas === 0);
    });
}

function mostrar(aviso) {
    if (!pushActivado || !soportado || Notification.permission !== 'granted') {
        return;
    }
    const notificacion = new Notification(aviso.titulo, { body: aviso.mensaje, tag: `notificacion-${aviso.id}` });
    // Al hacer clic se abre la bandeja.
    notificacion.onclick = () => {
        window.focus();
        window.location.href = url.replace(/\/nuevas$/, '');
    };
}

async function consultar() {
    try {
        const respuesta = await fetch(`${url}?desde=${leerUltimo()}`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        // Sesión vencida o cerrada (401), cambio de contraseña pendiente (redirección): se deja de consultar.
        if (!respuesta.ok || respuesta.redirected) {
            clearInterval(temporizador);
            return;
        }
        const datos = await respuesta.json();
        datos.avisos.forEach(mostrar);
        guardarUltimo(datos.ultimo_id);
        actualizarContador(datos.no_leidas);
    } catch {
        // Sin conexión: se intenta otra vez en el próximo minuto.
    }
}

// Botón «Activar avisos en este navegador» (bandeja y Mi perfil): pide el permiso del navegador.
function textoEstado() {
    if (!soportado) return 'Este navegador no admite avisos.';
    if (Notification.permission === 'granted') return 'Avisos activados en este navegador.';
    if (Notification.permission === 'denied') return 'El navegador bloqueó los avisos: habilítelos en la configuración del sitio.';
    return '';
}

function prepararBotones() {
    const estados = document.querySelectorAll('[data-estado-avisos]');
    const refrescar = () => estados.forEach((estado) => { estado.textContent = textoEstado(); });

    document.querySelectorAll('[data-activar-avisos]').forEach((boton) => {
        boton.disabled = !soportado || Notification.permission === 'granted';
        boton.addEventListener('click', async () => {
            await Notification.requestPermission();
            boton.disabled = Notification.permission === 'granted';
            refrescar();
        });
    });
    refrescar();
}

if (url) {
    prepararBotones();
    consultar();
    temporizador = setInterval(consultar, INTERVALO_MS);
}
