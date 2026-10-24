<?php

namespace Database\Seeders\Support;

use RuntimeException;

/**
 * Un registro de siembra incumpliría una regla o una cronología coherente.
 * Se lanza ANTES de escribir nada: el llamador puede descartar el candidato
 * (población extendida) o dejar que detenga la siembra (demo, donde un fallo
 * indica un error del escenario).
 */
final class SiembraInvalida extends RuntimeException {}
