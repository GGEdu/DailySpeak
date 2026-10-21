/**
 * Laravel's XSRF-TOKEN cookie, sent back so Sanctum accepts session-authenticated API calls.
 */
export function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * A URL as a path on this page's origin. The configured APP_URL may name another host, and the session
 * cookie only travels to the page's own origin.
 */
export function sameOriginPath(url, base = window.location.href) {
    const { pathname, search } = new URL(url, base);

    return pathname + search;
}

/**
 * POST a multipart form to the API with the user's session, throwing the server's message on failure.
 * Gives up after `timeout` ms, so a stalled connection cannot leave the caller waiting forever.
 */
export function postForm(url, body, { timeout = 30_000 } = {}) {
    return send(url, {
        method: 'POST',
        body,
        signal: AbortSignal.timeout(timeout),
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });
}

/**
 * POST JSON to the API with the user's session, throwing the server's message on failure.
 */
export function postJson(url, data, { signal } = {}) {
    return send(url, {
        method: 'POST',
        body: JSON.stringify(data),
        signal,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });
}

async function send(url, init) {
    const response = await fetch(url, init);

    if (!response.ok) {
        throw requestError(response, await response.json().catch(() => ({})));
    }

    // A 2xx answer only counts as a success when it is the JSON the caller expects; anything else
    // would otherwise be read as an empty result and cached as one.
    return response.json().catch(() => {
        throw requestError(response, { message: 'The server sent an unexpected answer.' });
    });
}

/**
 * The server's message, with the HTTP status so callers can tell a rate limit from an expired session.
 */
export function requestError(response, body) {
    const error = new Error(body?.message ?? `Request failed (HTTP ${response.status}).`);
    error.status = response.status;

    return error;
}
