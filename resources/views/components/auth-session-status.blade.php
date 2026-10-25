@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'alerta-exito']) }}>
        {{ $status }}
    </div>
@endif
