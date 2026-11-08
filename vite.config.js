import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            // notificaciones.js: CU37 paso 4, solo en el layout con sesión.
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/notificaciones.js'],
            refresh: true,
        }),
    ],
});
