<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VinculoPaciente extends Model
{
    protected $table = "vinculos_paciente";
    public $timestamps = false;

    protected $fillable = [
        "paciente_id",
        "persona_id",
        "recepcionista_verifica_id",
        "parentesco",
        "es_contacto_emergencia",
        "es_responsable_legal",
        "fecha_verificacion",
        "estado"
    ];

    // CU13 paso 4: parentescos que se ofrecen en el formulario.
    public const PARENTESCOS = ["Madre", "Padre", "Tutor legal", "Abuelo(a)", "Hermano(a)", "Cónyuge", "Hijo(a)", "Tío(a)", "Otro"];

    protected function casts(): array
    {
        return [
            "es_contacto_emergencia" => "boolean",
            "es_responsable_legal" => "boolean",
            "fecha_verificacion" => "datetime"
        ];
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, "paciente_id");
    }

    // ES_VINCULADA: el contacto o responsable.
    public function persona()
    {
        return $this->belongsTo(Persona::class, "persona_id");
    }

    // VERIFICA: recepcionista que revisó el documento físico (nulo si está pendiente).
    public function recepcionista()
    {
        return $this->belongsTo(Recepcionista::class, "recepcionista_verifica_id");
    }

    // CU13 paso 2: texto del tipo de vínculo.
    public function tipoTexto(): string
    {
        return match (true) {
            $this->es_contacto_emergencia && $this->es_responsable_legal => "Contacto de emergencia y responsable legal",
            $this->es_responsable_legal => "Responsable legal",
            default => "Contacto de emergencia",
        };
    }
}
