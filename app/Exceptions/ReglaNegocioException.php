<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Una regla de negocio impide la operación (RN03–RN20, RF07). El mensaje está en español y se
 * muestra tal cual al usuario: no lleva detalles internos. La lanzan los servicios (CitaService)
 * y la capturan los controladores para volver al formulario.
 */
class ReglaNegocioException extends RuntimeException
{
}
