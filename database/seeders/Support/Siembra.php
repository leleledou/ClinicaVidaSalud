<?php

namespace Database\Seeders\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Utilidades comunes de los seeders: reloj y zona horaria, búsqueda por clave
 * natural (sin ids fijos), inserción idempotente y contraseñas desde el entorno.
 *
 * Los seeders usan DB::table() porque todavía no existen los modelos Eloquent
 * definitivos. Nunca desactivan triggers ni restricciones.
 *
 * POLÍTICA DE FECHAS Y HORAS
 *  - Las columnas `timestamp(0)`, `date` y `time` de PostgreSQL no guardan zona.
 *    Los seeders las escriben SIEMPRE como hora local de la clínica
 *    (America/La_Paz, UTC-4 sin horario de verano), nunca en UTC.
 *  - Todo instante se calcula con zona() explícita (ahora(), instante()). Así la
 *    comparación «pasado/futuro» y las horas de las fichas (hora local) usan el
 *    mismo reloj, sea cual sea config('app.timezone') (hoy UTC en config/app.php).
 *  - Los seeders jamás dependen del DEFAULT CURRENT_TIMESTAMP de la base: cada
 *    timestamp se envía explícito.
 *  - Cambiar la zona global de Laravel es una decisión pendiente de aprobación
 *    (ver docs/decisiones-seeders.md §6).
 */
final class Siembra
{
    private const ZONA_CLINICA = 'America/La_Paz';

    private static ?CarbonImmutable $ahora = null;

    /** Zona horaria local de la clínica (puede sustituirse con SEED_ZONA_HORARIA en el entorno local). */
    public static function zona(): string
    {
        return (string) (env('SEED_ZONA_HORARIA') ?: self::ZONA_CLINICA);
    }

    /** Instante de referencia de toda la ejecución, en hora local de la clínica (se fija una vez por proceso). */
    public static function ahora(): CarbonImmutable
    {
        return self::$ahora ??= CarbonImmutable::now(self::zona());
    }

    /** Solo para pruebas en memoria: permite simular otra fecha de ejecución. */
    public static function fijarAhora(?CarbonImmutable $ahora): void
    {
        self::$ahora = $ahora?->setTimezone(self::zona());
    }

    /** Interpreta una fecha y hora almacenadas (hora local de la clínica) como instante. */
    public static function instante(string $fecha, string $hora = '00:00:00'): CarbonImmutable
    {
        return CarbonImmutable::parse($fecha.' '.$hora, self::zona());
    }

    /** Interpreta un `timestamp` leído de la base (hora local de la clínica). */
    public static function desdeTimestamp(string $timestamp): CarbonImmutable
    {
        return CarbonImmutable::parse($timestamp, self::zona());
    }

    /**
     * Lunes de la semana siguiente. Es estable durante toda la semana en curso
     * (lunes a domingo), de modo que reejecutar los seeders esa semana encuentra
     * las mismas fechas. Semana 0 = ancla; semana -1 = semana actual.
     */
    public static function ancla(): CarbonImmutable
    {
        return self::ahora()->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->startOfDay();
    }

    /** Primer día de la semana en curso: desde aquí no se generan fichas ya vencidas. */
    public static function inicioSemanaActual(): CarbonImmutable
    {
        return self::ancla()->subWeek();
    }

    /** Hora local de la clínica en el formato de las columnas `timestamp` sin zona. */
    public static function ts(CarbonImmutable $fecha): string
    {
        return $fecha->setTimezone(self::zona())->format('Y-m-d H:i:s');
    }

    /** Impide que un seeder de datos ficticios se ejecute en producción, incluso con `--class=`. */
    public static function exigirNoProduccion(string $seeder): void
    {
        if (app()->environment(['production', 'prod'])) {
            throw new RuntimeException("{$seeder} siembra datos ficticios y no puede ejecutarse en producción.");
        }
    }

    /** @param array<string,mixed> $donde */
    public static function id(string $tabla, array $donde): ?int
    {
        $consulta = DB::table($tabla);
        foreach ($donde as $columna => $valor) {
            $valor === null ? $consulta->whereNull($columna) : $consulta->where($columna, $valor);
        }
        $id = $consulta->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Devuelve el id del registro con esa clave natural; si no existe lo inserta.
     * Nunca actualiza un registro existente.
     *
     * @param array<string,mixed> $clave
     * @param array<string,mixed> $datos
     */
    public static function asegurar(string $tabla, array $clave, array $datos = []): int
    {
        return self::id($tabla, $clave) ?? (int) DB::table($tabla)->insertGetId($clave + $datos);
    }

    public static function rolId(string $nombre): int
    {
        return self::id('roles', ['nombre' => $nombre])
            ?? throw new RuntimeException("El rol {$nombre} no existe: ejecute primero CatalogosSeeder.");
    }

    /**
     * Persona identificada por (tipo, número, complemento) o, si no tiene
     * documento, por nombre completo y fecha de nacimiento.
     *
     * IMPORTANTE: una persona SIN documento se reconoce por su fecha de nacimiento,
     * que por tanto debe ser un literal fijo (nunca relativo a la fecha de ejecución);
     * si no, cada día se crearía una persona distinta.
     *
     * @param array<string,mixed> $d
     */
    public static function persona(array $d): int
    {
        $clave = isset($d['numero_documento'])
            ? [
                'tipo_documento' => $d['tipo_documento'],
                'numero_documento' => $d['numero_documento'],
                'complemento' => $d['complemento'] ?? null,
            ]
            : [
                'numero_documento' => null,
                'nombres' => $d['nombres'],
                'apellido_paterno' => $d['apellido_paterno'],
                'apellido_materno' => $d['apellido_materno'] ?? null,
                'fecha_nacimiento' => $d['fecha_nacimiento'],
            ];

        return self::asegurar('personas', $clave, array_diff_key($d, $clave));
    }

    /**
     * Cuenta de acceso (una por persona).
     *
     * @param array<string,mixed> $extra
     */
    public static function usuario(int $personaId, string $rol, string $nombreUsuario, string $correo, string $hash, array $extra = []): int
    {
        return self::asegurar('usuarios', ['persona_id' => $personaId], array_merge([
            'rol_id' => self::rolId($rol),
            'nombre_usuario' => $nombreUsuario,
            'correo' => $correo,
            'password' => $hash,
            'estado' => 'ACTIVO',
            'debe_cambiar_password' => false,
            // Cuenta ya en uso: correo verificado hace meses (los pacientes lo sobrescriben con su alta).
            'correo_verificado_en' => self::ts(self::ahora()->subDays(180)),
        ], $extra));
    }

    /**
     * Lee una contraseña de siembra desde una variable de entorno y devuelve su
     * hash bcrypt. Devuelve null si la variable no está definida. Nunca imprime
     * ni conserva el texto plano.
     *
     * Política: 12 o más caracteres, al menos tres clases (minúscula, mayúscula,
     * dígito, símbolo) y sin palabras o secuencias previsibles.
     */
    public static function hashDesdeEntorno(string $variable): ?string
    {
        $valor = env($variable);
        if (! is_string($valor) || $valor === '') {
            return null;
        }
        $clases = (int) (bool) preg_match('/[a-z]/', $valor) + (int) (bool) preg_match('/[A-Z]/', $valor)
            + (int) (bool) preg_match('/\d/', $valor) + (int) (bool) preg_match('/[^A-Za-z0-9]/', $valor);
        if (mb_strlen($valor) < 12 || $clases < 3 || preg_match('/vidasalud|clinica|password|contrase|12345|qwerty/i', $valor)) {
            throw new RuntimeException("{$variable} no cumple la política mínima: 12 o más caracteres, tres clases de caracteres y sin palabras previsibles.");
        }

        return Hash::make($valor);
    }
}
