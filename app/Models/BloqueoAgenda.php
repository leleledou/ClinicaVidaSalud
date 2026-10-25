<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BloqueoAgenda extends Model
{
    protected $table = "bloqueos_agenda";
    public $timestamps = false;

    protected $fillable = [
        "alcance",
        "medico_id",
        "consultorio_id",
        "inicio",
        "fin",
        "motivo"
    ];
}
