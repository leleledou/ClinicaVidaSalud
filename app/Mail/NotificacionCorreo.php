<?php

namespace App\Mail;

use App\Models\Notificacion;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * CU37 paso 1 (RF-38): correo de una notificación del canal CORREO. Asunto = título; cuerpo = título,
 * mensaje y nombre de la clínica (resources/views/correos/notificacion). Se envía con el mailer que ya
 * está configurado (MAIL_MAILER); no usa colas: NotificacionService sabe en el momento si se envió.
 */
class NotificacionCorreo extends Mailable
{
    public const CLINICA = "Clínica Vida Salud";

    public function __construct(public Notificacion $notificacion)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notificacion->titulo);
    }

    public function content(): Content
    {
        return new Content(view: "correos.notificacion", with: ["clinica" => self::CLINICA]);
    }
}
