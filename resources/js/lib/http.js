/**
 * Laravel's XSRF-TOKEN cookie, sent back so Sanctum accepts session-authenticated API calls.
 */
function xsrfToken() {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * POST a multipart form to the API with the user's session, throwing the server's message on failure.
 */
export async function postForm(url, body) {
    const response = await fetch(url, {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw requestError(response, data);
    }

    return data;
}

/**
 * POST JSON to the API with the user's session, throwing the server's message on failure.
 */
export async function postJson(url, data, { signal } = {}) {
    const response = await fetch(url, {
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

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw requestError(response, body);
    }

    return body;
}

/**
 * The server's message, with the HTTP status so callers can tell a rate limit from an expired session.
 */
function requestError(response, body) {
    const error = new Error(body.message ?? `Request failed (HTTP ${response.status}).`);
    error.status = response.status;

    return error;
}
