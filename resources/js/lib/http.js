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
        throw new Error(data.message ?? `Request failed (HTTP ${response.status}).`);
    }

    return data;
}
