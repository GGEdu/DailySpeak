/**
 * The address if it is a web link (http or https), otherwise null. Stops `javascript:` and `data:`
 * addresses from reaching an href, even if the server ever sends one.
 */
export function safeHttpUrl(value) {
    if (typeof value !== 'string' || value === '') {
        return null;
    }

    try {
        const url = new URL(value);

        return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : null;
    } catch {
        return null;
    }
}
