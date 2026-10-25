<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HistorialCita extends Model
{
    protected $table = "historiales_cita";
    public $timestamps = false;

    protected $fillable = [
        "cita_id",
        "usuario_realiza_id",
        "ficha_anterior_id",
        "ficha_nueva_id",
        "estado_anterior",
        "estado_nuevo",
        "canal",
        "motivo",
        "fecha_hora"
    ];

    protected function casts(): array
    {
        return [
            "fecha_hora" => "datetime"
        ];
    }

    public function cita()
    {
        return $this->belongsTo(Cita::class, "cita_id");
    }

    // Nulo cuando el cambio lo hizo el SISTEMA (ck_historiales_usuario_sistema).
    public function usuario()
    {
        return $this->belongsTo(Usuario::class, "usuario_realiza_id");
    }

    public function fichaAnterior()
    {
        return $this->belongsTo(Ficha::class, "ficha_anterior_id");
    }

    public function fichaNueva()
    {
        return $this->belongsTo(Ficha::class, "ficha_nueva_id");
    }
}
