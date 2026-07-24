import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';

// Single source of truth for the dev stack's public URL — same APP_URL the
// backend uses for share links, Slack redirects, etc. VITE_ORIGIN/
// VITE_HMR_HOST still win if set explicitly, for the rare case Vite needs to
// diverge from the app's own URL.
const appUrl = process.env.APP_URL ? new URL(process.env.APP_URL) : null;

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
    // Baked into the built bundle at `vite build` time — resources/js/echo.js
    // reads these via import.meta.env. Reverb is proxied through the app's
    // own origin (docker/nginx-proxy.conf's /app/ location), so the browser
    // client should connect there too, not to the internal REVERB_HOST/PORT
    // the Laravel↔Reverb server-to-server link uses.
    define: {
        'import.meta.env.VITE_REVERB_HOST':
            JSON.stringify(process.env.VITE_REVERB_HOST || appUrl?.hostname),
        'import.meta.env.VITE_REVERB_SCHEME':
            JSON.stringify(process.env.VITE_REVERB_SCHEME || (appUrl?.protocol === 'https:' ? 'https' : 'http')),
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        // When running behind a reverse proxy (e.g. local Sail+nginx setup,
        // or an ngrok tunnel), browsers need same-origin asset/HMR URLs.
        origin: process.env.VITE_ORIGIN || appUrl?.origin,
        cors: true,
        hmr: (process.env.VITE_HMR_HOST || appUrl)
            ? {
                  host: process.env.VITE_HMR_HOST || appUrl.hostname,
                  protocol: process.env.VITE_HMR_PROTOCOL
                      || (appUrl?.protocol === 'https:' ? 'wss' : 'ws'),
                  clientPort: parseInt(
                      process.env.VITE_HMR_CLIENT_PORT
                          || appUrl?.port
                          || (appUrl?.protocol === 'https:' ? '443' : '80'),
                      10,
                  ),
              }
            : undefined,
        watch: { ignored: ['**/storage/framework/views/**'] },
    },
});
