<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Persona extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "tipo_documento",
        "numero_documento",
        "complemento",
        "expedido",
        "nombres",
        "apellido_paterno",
        "apellido_materno",
        "fecha_nacimiento",
        "sexo",
        "telefono",
        "correo",
        "direccion"
    ];

    // Tipos de documento válidos (ck_personas_tipo_documento) con su texto para los selects.
    public const TIPOS_DOCUMENTO = [
        "CI" => "Carnet de identidad",
        "PASAPORTE" => "Pasaporte",
        "CARNET_EXTRANJERO" => "Carnet de extranjero",
        "CERTIFICADO_NACIMIENTO" => "Certificado de nacimiento"
    ];

    // Lugar de expedición del documento.
    public const EXPEDIDOS = [
        "LP" => "LP", "CB" => "CB", "SC" => "SC", "OR" => "OR", "PT" => "PT",
        "TJ" => "TJ", "CH" => "CH", "BE" => "BE", "PD" => "PD", "EX" => "Extranjero"
    ];

    // Teléfono: dígitos, +, espacios y guiones (CU10 paso 5; el mismo en JavaScript).
    public const FORMATO_TELEFONO = "/^[0-9+\-\s]{7,20}$/";

    protected function casts(): array
    {
        return [
            "fecha_nacimiento" => "date:Y-m-d"
        ];
    }

    // CU10 paso 4: el número de documento y el complemento siempre se guardan normalizados.
    protected static function booted(): void
    {
        static::saving(function (Persona $persona) {
            $persona->numero_documento = self::normalizarDocumento($persona->numero_documento);
            $persona->complemento = self::normalizarDocumento($persona->complemento);
        });
    }

    public function usuario()
    {
        return $this->hasOne(Usuario::class, "persona_id");
    }

    public function medico()
    {
        return $this->hasOne(Medico::class, "persona_id");
    }

    public function paciente()
    {
        return $this->hasOne(Paciente::class, "persona_id");
    }

    public function recepcionista()
    {
        return $this->hasOne(Recepcionista::class, "persona_id");
    }

    public function administrador()
    {
        return $this->hasOne(Administrador::class, "persona_id");
    }

    // CU13: vínculos en los que esta persona es contacto o responsable de un paciente.
    public function vinculosComoContacto()
    {
        return $this->hasMany(VinculoPaciente::class, "persona_id");
    }

    // CU10 paso 5 / CU12 paso 5 / CU13 paso 3: reglas de los datos personales del formulario
    // personas/_campos (el documento es opcional; cada CU agrega lo que exige).
    public static function reglas(): array
    {
        return [
            // Si hay número debe haber tipo.
            "tipo_documento" => ["nullable", "required_with:numero_documento", "in:" . implode(",", array_keys(self::TIPOS_DOCUMENTO))],
            "numero_documento" => ["nullable", "string", "max:30"],
            "complemento" => ["nullable", "string", "max:10"],
            "expedido" => ["nullable", "in:" . implode(",", array_keys(self::EXPEDIDOS))],
            "nombres" => ["required", "string", "max:255"],
            "apellido_paterno" => ["required", "string", "max:255"],
            "apellido_materno" => ["nullable", "string", "max:255"],
            "fecha_nacimiento" => ["bail", "required", "date", "before_or_equal:today"],
            "sexo" => ["required", "in:M,F"],
            "telefono" => ["required", "string", "regex:" . self::FORMATO_TELEFONO],
            "correo" => ["nullable", "email", "max:255"],
            "direccion" => ["nullable", "string", "max:255"],
        ];
    }

    // Mensajes en español de reglas() (los mismos que muestra la validación en JavaScript).
    public static function mensajes(): array
    {
        return [
            "tipo_documento.required" => "El campo Tipo de documento es obligatorio.",
            "tipo_documento.required_with" => "Indique el tipo de documento.",
            "tipo_documento.in" => "El tipo de documento no es válido.",
            "numero_documento.required" => "El campo Número de documento es obligatorio.",
            "numero_documento.max" => "El número de documento no puede superar los 30 caracteres.",
            "complemento.max" => "El complemento no puede superar los 10 caracteres.",
            "expedido.in" => "El lugar de expedición no es válido.",
            "nombres.required" => "El campo Nombres es obligatorio.",
            "nombres.max" => "Los nombres no pueden superar los 255 caracteres.",
            "apellido_paterno.required" => "El campo Apellido paterno es obligatorio.",
            "apellido_paterno.max" => "El apellido paterno no puede superar los 255 caracteres.",
            "apellido_materno.max" => "El apellido materno no puede superar los 255 caracteres.",
            "fecha_nacimiento.required" => "El campo Fecha de nacimiento es obligatorio.",
            "fecha_nacimiento.date" => "La fecha de nacimiento no es válida.",
            "fecha_nacimiento.before_or_equal" => "La fecha de nacimiento no puede ser futura.",
            "sexo.required" => "El campo Sexo es obligatorio.",
            "sexo.in" => "El sexo debe ser M o F.",
            "telefono.required" => "El campo Teléfono es obligatorio.",
            "telefono.regex" => "El teléfono solo puede tener dígitos, +, espacios y guiones (entre 7 y 20 caracteres).",
            "correo.required" => "El campo Correo es obligatorio.",
            "correo.email" => "El correo no tiene un formato válido.",
            "correo.max" => "El correo no puede superar los 255 caracteres.",
            "direccion.max" => "La dirección no puede superar los 255 caracteres.",
        ];
    }

    // CU10 paso 4: documento normalizado en los datos del formulario. Sin número no se guarda
    // tipo, complemento ni lugar de expedición.
    public static function normalizarEntrada(array $datos): array
    {
        foreach (["numero_documento", "complemento"] as $campo) {
            if (isset($datos[$campo]) && is_string($datos[$campo])) {
                $datos[$campo] = self::normalizarDocumento($datos[$campo]);
            }
        }
        if (empty($datos["numero_documento"])) {
            $datos = array_merge($datos, ["numero_documento" => null, "tipo_documento" => null, "complemento" => null, "expedido" => null]);
        }

        return $datos;
    }

    // CU10 paso 4: mayúsculas, sin espacios, puntos ni guiones; null si queda vacío.
    public static function normalizarDocumento(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $normalizado = preg_replace("/[\s.\-]+/u", "", mb_strtoupper($valor));

        return $normalizado === "" ? null : $normalizado;
    }

    // CU07 paso 4 / CU10 paso 2: persona con ese documento, comparando normalizado en los dos
    // lados (consulta parametrizada). «expedido» no forma parte de la unicidad (RF02).
    public static function buscarPorDocumento(string $tipo, string $numero, ?string $complemento): ?self
    {
        return self::where("tipo_documento", $tipo)
            ->whereRaw("regexp_replace(upper(numero_documento), '[\\s.\\-]', '', 'g') = ?", [self::normalizarDocumento($numero)])
            ->whereRaw("coalesce(regexp_replace(upper(complemento), '[\\s.\\-]', '', 'g'), '') = ?", [self::normalizarDocumento($complemento) ?? ""])
            ->first();
    }

    // Nombres + apellido paterno + apellido materno.
    protected function nombreCompleto(): Attribute
    {
        return Attribute::get(fn () => trim($this->nombres . " " . $this->apellido_paterno . " " . ($this->apellido_materno ?? "")));
    }

    // RN15 / R-EDAD: años cumplidos en una fecha (por ejemplo, la de la cita).
    public function edadEn(Carbon $fecha): int
    {
        // diff()->y: años completos entre el nacimiento y la fecha.
        return $this->fecha_nacimiento->diff($fecha->copy()->startOfDay())->y;
    }

    // Años cumplidos a hoy.
    protected function edad(): Attribute
    {
        return Attribute::get(fn () => $this->fecha_nacimiento?->age);
    }
}
