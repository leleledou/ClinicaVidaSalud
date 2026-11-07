<?php

namespace App\Exceptions;

/**
 * CU32 paso 3 (RF-33, corrección 5 del diagrama v3.1): el medicamento coincide con una alergia ACTIVA
 * del paciente y el médico todavía no confirmó que la revisó. No bloquea: el formulario vuelve con el
 * aviso y la casilla de confirmación.
 */
class AlergiaNoConfirmadaException extends ReglaNegocioException
{
}
