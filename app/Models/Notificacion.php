<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notificacion extends Model
{
    protected $table = "notificaciones";
    public $timestamps = false;

    protected $fillable = [
        "persona_id",
        "tipo",
        "canal",
        "titulo",
        "mensaje",
        "referencia_tipo",
        "referencia_id",
        "programada_para",
        "enviada_en",
        "leida_en",
        "estado",
        "intentos"
    ];

    protected function casts(): array
    {
        return [
            "programada_para" => "datetime",
            "enviada_en" => "datetime",
            "leida_en" => "datetime"
        ];
    }

    // RECIBE: la persona destinataria (tenga o no cuenta).
    public function persona()
    {
        return $this->belongsTo(Persona::class, "persona_id");
    }
}
