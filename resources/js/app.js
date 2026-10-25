import './bootstrap';

import Alpine from 'alpinejs';
import { formularioPersona, validarMedico, validarPaciente, validarRegistro, validarRol, validarUsuario, validarVinculo } from './personas';
import { revisarPassword, requisitosPassword } from './password';
import { formularioCita, validarCita, validarFiltrosFichas } from './citas';

window.Alpine = Alpine;

// CU07, CU10, CU04 y CU13: formulario con datos de la persona y su validación en el cliente.
Alpine.data('formularioPersona', formularioPersona);
window.validarMedico = validarMedico;
window.validarPaciente = validarPaciente;
window.validarUsuario = validarUsuario;
// CU13: formulario de vínculo.
window.validarVinculo = validarVinculo;
// CU05: formulario de rol.
window.validarRol = validarRol;
// CU12: formulario de autorregistro.
window.validarRegistro = validarRegistro;
// CU03 y CU12: requisitos de la contraseña marcados en vivo.
window.revisarPassword = revisarPassword;
window.requisitosPassword = requisitosPassword;
// CU14: filtros de disponibilidad. CU15: formulario de cita.
window.validarFiltrosFichas = validarFiltrosFichas;
window.validarCita = validarCita;
Alpine.data('formularioCita', formularioCita);

Alpine.start();
