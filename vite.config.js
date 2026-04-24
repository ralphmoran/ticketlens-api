import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
        tailwindcss(),
        vue(),
    ],
    resolve: {
        alias: { '@': '/resources/js' },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // When running behind a reverse proxy (e.g. local Sail+nginx setup),
        // set VITE_ORIGIN/VITE_HMR_HOST so browsers fetch assets same-origin.
        origin: process.env.VITE_ORIGIN || undefined,
        cors: true,
        hmr: process.env.VITE_HMR_HOST
            ? {
                  host: process.env.VITE_HMR_HOST,
                  protocol: process.env.VITE_HMR_PROTOCOL || 'ws',
                  clientPort: parseInt(process.env.VITE_HMR_CLIENT_PORT || '80', 10),
              }
            : undefined,
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
