@props(['value'])

<label {{ $attributes->merge(['class' => 'etiqueta']) }}>
    {{ $value ?? $slot }}
</label>
