{{-- CU37 paso 1 (RF-38): correo de una notificación del canal CORREO (NotificacionCorreo). Título, mensaje y
     nombre de la clínica. Estilos en línea: los programas de correo no cargan hojas de estilo. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $notificacion->titulo }}</title>
</head>
<body style="margin: 0; padding: 24px; background: #f1f5f9; font-family: Arial, Helvetica, sans-serif; color: #1e293b;">
    <div style="max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 8px; padding: 24px;">
        <p style="margin: 0 0 16px; font-size: 14px; font-weight: bold; color: #0f766e;">{{ $clinica }}</p>
        <h1 style="margin: 0 0 12px; font-size: 20px; color: {{ $notificacion->esCritica() ? '#dc2626' : '#1e3a5f' }};">{{ $notificacion->titulo }}</h1>
        <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.5;">{{ $notificacion->mensaje }}</p>
        <p style="margin: 0; font-size: 12px; color: #64748b;">Este es un aviso automático de {{ $clinica }}. Por favor, no responda a este correo.</p>
    </div>
</body>
</html>
