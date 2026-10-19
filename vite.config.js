import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
    // Preserve the existing global stylesheet while splitting page JavaScript.
    build: { cssCodeSplit: false },
    optimizeDeps: {
        include: ["swiper"],
    },
    plugins: [
        laravel({
            input: 'resources/js/app.jsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    // Bind the dev server to IPv4 so the generated `public/hot` uses
    // http://127.0.0.1:5173 — matching how the Laravel app is browsed
    // (127.0.0.1:8000). Without this, Vite writes an IPv6 `[::1]` host and
    // the browser can't load assets, leaving the page blank.
    server: {
        host: '127.0.0.1',
        hmr: {
            host: '127.0.0.1',
        },
    },
    resolve: {
        alias: {
            "@": "/resources/js",
            "@assets": "/resources/assets"
        }
    }
});
