<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistoriaClinica extends Model
{
    protected $table = "historias_clinicas";
    public $timestamps = false;

    protected $fillable = [
        "paciente_id",
        "fecha_apertura",
        "grupo_sanguineo",
        "observaciones"
    ];

    // CU29 paso 1: grupos sanguíneos de ck_historias_grupo_sanguineo con su texto legible.
    public const GRUPOS_SANGUINEOS = [
        "A_POS" => "A+", "A_NEG" => "A−", "B_POS" => "B+", "B_NEG" => "B−",
        "AB_POS" => "AB+", "AB_NEG" => "AB−", "O_POS" => "O+", "O_NEG" => "O−",
        "DESCONOCIDO" => "Desconocido",
    ];

    protected function casts(): array
    {
        return [
            "fecha_apertura" => "date:Y-m-d"
        ];
    }

    // POSEE: la historia es del paciente (una por paciente).
    public function paciente()
    {
        return $this->belongsTo(Paciente::class, "paciente_id");
    }

    public function antecedentes()
    {
        return $this->hasMany(Antecedente::class, "historia_clinica_id");
    }

    public function alergias()
    {
        return $this->hasMany(Alergia::class, "historia_clinica_id");
    }

    // CU30 paso 3: grupo sanguíneo en texto (A+, O−, Desconocido).
    public function textoGrupoSanguineo(): string
    {
        return self::GRUPOS_SANGUINEOS[$this->grupo_sanguineo] ?? $this->grupo_sanguineo;
    }

    // CU29: editan la historia, los antecedentes y las alergias solo el médico con historia.gestionar
    // y acceso clínico al paciente (Paciente::accesoClinico). El paciente solo consulta (A1).
    public function puedeEditar(Usuario $usuario): bool
    {
        return $usuario->tienePermiso("historia.gestionar") && $this->paciente->accesoClinico($usuario) === "medico";
    }
}
