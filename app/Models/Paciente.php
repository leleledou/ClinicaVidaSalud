<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Paciente extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "recepcionista_registra_id",
        "codigo",
        "estado",
        "fecha_registro"
    ];

    protected function casts(): array
    {
        return [
            "fecha_registro" => "datetime"
        ];
    }

    public function persona()
    {
        return $this->belongsTo(Persona::class, "persona_id");
    }

    // REGISTRA: nulo si el paciente se autorregistró.
    public function recepcionista()
    {
        return $this->belongsTo(Recepcionista::class, "recepcionista_registra_id");
    }

    public function historiaClinica()
    {
        return $this->hasOne(HistoriaClinica::class, "paciente_id");
    }

    public function citas()
    {
        return $this->hasMany(Cita::class, "paciente_id");
    }
}
