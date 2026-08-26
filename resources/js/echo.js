import Echo from 'laravel-echo';

import Pusher from 'pusher-js';

window.Pusher = Pusher;

const reverbAppKey = import.meta.env.VITE_REVERB_APP_KEY || 'local';
const reverbHost = import.meta.env.VITE_REVERB_HOST || window.location.hostname || '127.0.0.1';
const reverbPort = Number(import.meta.env.VITE_REVERB_PORT || 8080);
const reverbScheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
const forceTLS = reverbScheme === 'https';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: reverbAppKey,
    wsHost: reverbHost,
    wsPort: reverbPort,
    wssPort: reverbPort,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
        withCredentials: true,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
    },
});
