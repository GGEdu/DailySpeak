/**
 * "world" → "World", "science_and_tech" → "Science and tech". The catalogue's categories come as keys, without labels.
 */
export function categoryName(category) {
    const words = String(category ?? '')
        .replace(/[-_]+/g, ' ')
        .trim();

    if (words === '') {
        return 'Other';
    }

    return words.charAt(0).toUpperCase() + words.slice(1);
}

/**
 * The sources grouped by category, the groups in alphabetical order and the sources in the order they came.
 *
 * @returns {{ label: string, sources: object[] }[]}
 */
export function groupByCategory(sources = []) {
    const groups = new Map();

    for (const source of sources) {
        const label = categoryName(source.category);

        groups.set(label, [...(groups.get(label) ?? []), source]);
    }

    return [...groups]
        .map(([label, grouped]) => ({ label, sources: grouped }))
        .sort((a, b) => a.label.localeCompare(b.label, 'en'));
}
