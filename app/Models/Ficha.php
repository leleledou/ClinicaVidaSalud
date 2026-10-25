<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Ficha extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "horario_atencion_id",
        "fecha",
        "hora_inicio",
        "hora_fin",
        "estado"
    ];

    public function horario()
    {
        return $this->belongsTo(HorarioAtencion::class, "horario_atencion_id");
    }

    public function citas()
    {
        return $this->hasMany(Cita::class, "ficha_id");
    }

    // RN07: la cita activa de la ficha (estado distinto de CANCELADA; ux_citas_ficha_activa admite una).
    public function citaActiva()
    {
        return $this->hasOne(Cita::class, "ficha_id")->where("estado", "<>", "CANCELADA");
    }

    // R-OCUP: «ocupada» no se guarda; se deriva de tener una cita activa.
    public function ocupada(): bool
    {
        return $this->relationLoaded("citaActiva") ? $this->citaActiva !== null : $this->citaActiva()->exists();
    }

    // Inicio de la ficha como fecha y hora (RN06: no se reserva en el pasado). «fecha» queda como
    // texto AAAA-MM-DD (sin cast) porque CU09 agrupa las fichas por ese valor.
    public function inicio(): Carbon
    {
        return Carbon::parse($this->fecha)->setTimeFromTimeString($this->hora_inicio);
    }
}
