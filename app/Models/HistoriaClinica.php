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
}
