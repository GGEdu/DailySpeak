/**
 * The tabs of the user's settings and of the platform's panel, and the menu that leads to them.
 */

export const SETTINGS_TABS = [
    { label: 'Profile', href: '/settings' },
    { label: 'AI', href: '/settings/ai' },
    { label: 'Sources', href: '/settings/sources' },
    { label: 'Usage', href: '/settings/usage' },
];

export const PLATFORM_TABS = [
    { label: 'Overview', href: '/admin' },
    { label: 'Users', href: '/admin/users' },
    { label: 'AI', href: '/admin/ai' },
    { label: 'Limits', href: '/admin/limits' },
    { label: 'Sources', href: '/admin/sources' },
    { label: 'Privacy', href: '/admin/privacy' },
    // Horizon has its own page, outside the app: a new tab keeps this panel open.
    { label: 'Jobs', href: '/horizon', external: true },
];

// The first tab of each section is the section's front page: only that exact address opens it.
const FRONT_PAGES = new Set(['/settings', '/admin']);

const pathOf = (url) => String(url ?? '').split(/[?#]/)[0];

/**
 * Whether the page on screen is a tab. Its sub-pages count too ("/settings/ai/…" is the AI tab), except for the front pages.
 */
export function isCurrentTab(url, href) {
    const path = pathOf(url);

    if (path === href) {
        return true;
    }

    return !FRONT_PAGES.has(href) && path.startsWith(`${href}/`);
}

/**
 * Whether a section of the menu is the one on screen. Every page of the section counts, the front page included.
 */
export function isCurrentSection(url, href) {
    const path = pathOf(url);

    return path === href || path.startsWith(`${href}/`);
}
