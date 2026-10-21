import { configureEcho, echoIsConfigured } from '@laravel/echo-vue';

/**
 * Configure Echo (Reverb) once per page load. A second configureEcho() replaces the instance without closing its
 * socket, so every visit to the debate page would leave one more connection open until the next reload.
 */
export function configureRealtime({ configure = configureEcho, isConfigured = echoIsConfigured } = {}) {
    if (!isConfigured()) {
        configure({ broadcaster: 'reverb' });
    }
}
