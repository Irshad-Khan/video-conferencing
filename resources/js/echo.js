import Echo from 'laravel-echo';

import Pusher from 'pusher-js';
window.Pusher = Pusher;

// When the app is opened from another device (LAN IP / hostname instead of localhost),
// the baked-in VITE_REVERB_* values point at the dev machine's "localhost" and can't work.
// In that case use the page's own origin — nginx proxies /app to Reverb there (HTTPS,
// since camera/mic are only available in secure contexts).
const isLocal = ['localhost', '127.0.0.1'].includes(window.location.hostname);
const sameOriginTls = window.location.protocol === 'https:';
const originPort = Number(window.location.port) || (sameOriginTls ? 443 : 80);

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: isLocal ? import.meta.env.VITE_REVERB_HOST : window.location.hostname,
    wsPort: isLocal ? (import.meta.env.VITE_REVERB_PORT ?? 80) : originPort,
    wssPort: isLocal ? (import.meta.env.VITE_REVERB_PORT ?? 443) : originPort,
    forceTLS: isLocal ? (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https' : sameOriginTls,
    enabledTransports: ['ws', 'wss'],
});
