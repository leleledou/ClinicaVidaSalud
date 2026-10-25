<button {{ $attributes->merge(['type' => 'submit', 'class' => 'btn-primario']) }}>
    {{ $slot }}
</button>
