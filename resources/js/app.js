import { createInertiaApp } from '@inertiajs/vue3';
import { configureEcho } from '@laravel/echo-vue';
import { createApp, h } from 'vue';
import { broadcastAuthOptions } from './lib/broadcasting.js';

// Reads VITE_REVERB_* from .env; private channels are authorised at /broadcasting/auth.
configureEcho({
    broadcaster: 'reverb',
    channelAuthorization: broadcastAuthOptions(),
});

createInertiaApp({
    title: (title) => (title ? `${title} · DailySpeak` : 'DailySpeak'),
    resolve: (name) => {
        const pages = import.meta.glob('./pages/**/*.vue');

        return pages[`./pages/${name}.vue`]();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
    progress: {
        color: '#22d3ee',
    },
});
