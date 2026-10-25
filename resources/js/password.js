/*
 * CU03 paso 5 / CU12 paso 5: requisitos de la contraseña marcados mientras se escribe.
 * Las mismas reglas que App\Rules\PoliticaPassword (el servidor vuelve a validar).
 */

// Requisitos de la política: largo, mayúscula, minúscula, número y símbolo.
export function revisarPassword(valor) {
    return {
        largo: valor.length >= 8,
        mayuscula: /\p{Lu}/u.test(valor),
        minuscula: /\p{Ll}/u.test(valor),
        numero: /\d/.test(valor),
        simbolo: /[^\p{L}\p{N}\s]/u.test(valor),
    };
}

/*
 * Conecta una lista <ul> con <li data-requisito="..."> a los campos de contraseña.
 * config: { lista, campos (inputs que repintan al escribir), estado () => { requisito: bool }, escrito () => bool }
 * Devuelve pintar(forzar): verde = cumplido, rojo = falta (gris mientras no se escribe nada,
 * salvo forzar = true al intentar guardar). pintar() devuelve el estado.
 */
export function requisitosPassword(config) {
    function pintar(forzar = false) {
        const estado = config.estado();
        const escrito = config.escrito() || forzar;
        config.lista.querySelectorAll('li[data-requisito]').forEach((item) => {
            const cumple = estado[item.dataset.requisito];
            item.classList.toggle('text-green-700', cumple);
            item.classList.toggle('text-red-600', ! cumple && escrito);
            item.classList.toggle('text-gray-500', ! cumple && ! escrito);
            item.textContent = (cumple ? '✔ ' : '✘ ') + item.textContent.replace(/^[✔✘] /, '');
        });
        return estado;
    }

    config.campos.forEach((campo) => campo.addEventListener('input', () => pintar()));
    pintar();

    return pintar;
}
