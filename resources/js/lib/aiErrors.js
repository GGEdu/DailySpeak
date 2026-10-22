/**
 * Where an AI error sends the learner. The API answers with a `code` and a status: 409 `ai_not_configured` when the
 * user has no key for the task (and the platform is not allowed), 429 `ai_cap_reached` at the monthly cap. The web
 * pages get the session error `ai` instead, which does not say which of the two it is.
 */

export const AI_SETTINGS_LINK = { href: '/settings/ai', label: 'Open AI settings' };
export const USAGE_LINK = { href: '/settings/usage', label: 'See your usage' };

/**
 * The links for the session error `ai`: it does not say which of the two causes it is, so both pages are offered.
 */
export const SESSION_AI_LINKS = [AI_SETTINGS_LINK, USAGE_LINK];

/**
 * The links for an API code. Any other code (or none) needs no link.
 */
export function aiLinksFor(code) {
    switch (code) {
        case 'ai_not_configured':
            return [AI_SETTINGS_LINK];
        case 'ai_cap_reached':
            return [USAGE_LINK];
        default:
            return [];
    }
}

/**
 * The links for an error thrown by lib/http.js.
 */
export function errorLinks(error) {
    return aiLinksFor(error?.code);
}
