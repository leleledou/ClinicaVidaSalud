import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // notificaciones.js: CU37 paso 4; voz.js: CU39 paso 1. Solo en el layout con sesión.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/notificaciones.js', 'resources/js/voz.js'],
            refresh: true,
        }),
    ],
});
