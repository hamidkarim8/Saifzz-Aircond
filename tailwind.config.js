import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            // Design tokens — docs/05-design-system.md
            fontFamily: {
                sans: ['"Plus Jakarta Sans"', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // Navy → blue scale
                navy: {
                    900: '#0A1628', // c1 — sidebar, portal bg
                    800: '#0E2040', // c2 — headings, total bars
                    700: '#1A3A5C', // c3 — accents
                    600: '#2C5A87', // dividers ON a navy surface
                    300: '#9DBAD6', // muted text ON a navy surface (labels, counts, links)
                },
                primary: {
                    DEFAULT: '#1E6FAE', // c4
                    hover: '#2E8FD4', // c5
                    300: '#5AAFE8', // c6
                    50: '#EBF6FD', // c9
                },
                appbg: '#F0F4F8',
                surface: {
                    DEFAULT: '#FFFFFF',
                    muted: '#F7FAFC',
                },
                line: {
                    DEFAULT: '#DDE6EE', // bd
                    strong: '#C5D5E4', // bd2
                },
                ink: {
                    DEFAULT: '#0A1628', // tx
                    soft: '#4A6278', // tx2
                    muted: '#8BAABB', // tx3
                },
                // Semantic
                ok: { DEFAULT: '#16A34A', bg: '#DCFCE7' },
                warn: { DEFAULT: '#D97706', bg: '#FEF3C7' },
                danger: { DEFAULT: '#DC2626', bg: '#FEE2E2' },
                wa: '#25D366',
                invoice: { DEFAULT: '#6366F1', bg: '#EDE9FE' },
            },
            borderRadius: {
                ra: '10px',
                ral: '14px',
                rax: '18px',
            },
            boxShadow: {
                card: '0 1px 8px rgba(10,22,40,.08)',
                lift: '0 8px 32px rgba(10,22,40,.14)',
            },
        },
    },

    plugins: [forms],
};
