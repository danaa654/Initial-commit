import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                // Site-wide default — applied to <html> by Tailwind's
                // preflight, so every button, label, table header, and
                // table cell picks this up automatically with no
                // per-page edits. Matches Welcome.vue: everything on
                // that page except the "CLASSLY" title itself is IBM
                // Plex Sans (the tagline, card titles, and card
                // descriptions are all this one font, just different
                // sizes/weights).
                sans: ['IBM Plex Sans', ...defaultTheme.fontFamily.sans],
                // Reserved for big page titles (h1) — see the global
                // rule in app-shell.css. Matches the "CLASSLY" treatment
                // on Welcome.vue. Also available as the `font-serif`
                // utility anywhere else you want that same look.
                serif: ['Fraunces', ...defaultTheme.fontFamily.serif],
            },
        },
    },

    plugins: [forms],
};