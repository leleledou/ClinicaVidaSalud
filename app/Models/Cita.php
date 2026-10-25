<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cita extends Model
{
    public $timestamps = false;

    protected $fillable = [
        "usuario_registra_id",
        "paciente_id",
        "ficha_id",
        "seguro_paciente_id",
        "administrador_autoriza_id",
        "cita_reemplazada_id",
        "codigo",
        "origen",
        "canal",
        "motivo",
        "estado",
        "codigo_autorizacion_seguro",
        "enlace_virtual",
        "motivo_cancelacion",
        "justificacion_excepcion",
        "fecha_registro"
    ];

    // RF06: estados de la cita (ck_citas_estado). Activa = cualquiera distinto de CANCELADA.
    public const ESTADOS = ["PROGRAMADA", "CONFIRMADA", "ATENDIDA", "CANCELADA", "INASISTENCIA"];

    protected function casts(): array
    {
        return [
            "fecha_registro" => "datetime"
        ];
    }

    public function paciente()
    {
        return $this->belongsTo(Paciente::class, "paciente_id");
    }

    public function ficha()
    {
        return $this->belongsTo(Ficha::class, "ficha_id");
    }

    // REGISTRA: la cuenta que la programó (recepción o el propio paciente).
    public function usuarioRegistra()
    {
        return $this->belongsTo(Usuario::class, "usuario_registra_id");
    }

    // RN20: seguro con el que se atiende (opcional).
    public function seguroPaciente()
    {
        return $this->belongsTo(PacienteSeguro::class, "seguro_paciente_id");
    }

    // RF09: historial de cambios de estado y de ficha.
    public function historiales()
    {
        return $this->hasMany(HistorialCita::class, "cita_id");
    }

    // /ATIENDE (derivado, sin FK): cita → ficha → horario → médico-especialidad → médico.
    public function medico(): ?Medico
    {
        return $this->ficha?->horario?->medicoEspecialidad?->medico;
    }

    // Especialidad derivada por el mismo camino.
    public function especialidad(): ?Especialidad
    {
        return $this->ficha?->horario?->medicoEspecialidad?->especialidad;
    }
}
