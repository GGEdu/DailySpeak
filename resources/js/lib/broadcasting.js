import { xsrfToken } from './http.js';

/**
 * Channel authorisation for Echo's private channels. The XSRF cookie is read on every request, not
 * copied once when Echo starts: the session token changes on login and logout, so a copy taken at
 * page load goes stale. Laravel's broadcasting route currently skips the token check, so this only
 * matters if that check is switched back on.
 */
export function broadcastAuthOptions() {
    return {
        transport: 'ajax',
        endpoint: '/broadcasting/auth',
        headersProvider: () => ({ 'X-XSRF-TOKEN': xsrfToken() }),
    };
}
