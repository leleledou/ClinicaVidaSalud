import './bootstrap';

import Alpine from 'alpinejs';
import { formularioPersona, validarMedico, validarPaciente, validarRol, validarUsuario } from './personas';

window.Alpine = Alpine;

// CU07, CU10 y CU04: formulario con datos de la persona y su validación en el cliente.
Alpine.data('formularioPersona', formularioPersona);
window.validarMedico = validarMedico;
window.validarPaciente = validarPaciente;
window.validarUsuario = validarUsuario;
// CU05: formulario de rol.
window.validarRol = validarRol;

Alpine.start();
