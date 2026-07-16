/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ['./app/Views/**/*.php'],
    darkMode: 'class',
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#ECF3FF',
                    100: '#DDE9FF',
                    200: '#C2D6FF',
                    300: '#9CB9FF',
                    400: '#7592FF',
                    500: '#465FFF',
                    600: '#3641F5',
                    700: '#2A31D8',
                    800: '#252DAE',
                    900: '#262E89',
                },
            },
        },
    },
    plugins: [],
};
