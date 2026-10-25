<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

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

    // Años cumplidos a hoy.
    protected function edad(): Attribute
    {
        return Attribute::get(fn () => $this->fecha_nacimiento?->age);
    }
}
