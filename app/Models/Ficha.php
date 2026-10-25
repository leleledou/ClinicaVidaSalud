<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
