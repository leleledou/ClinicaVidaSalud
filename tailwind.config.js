import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        // password.js pinta los requisitos con clases de Tailwind (text-green-700, text-red-600, text-gray-500).
        './resources/js/**/*.js',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Azul marino de los prototipos (menú lateral y títulos).
                marino: {
                    700: '#1B5670',
                    800: '#13465F',
                    900: '#0E3A53',
                },
            },
        },
    },

    plugins: [forms],
};
