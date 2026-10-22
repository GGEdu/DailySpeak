// node --test resources/js/lib/sources.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { categoryName, groupByCategory } from './sources.js';

test('a category key reads as a name', () => {
    assert.equal(categoryName('world'), 'World');
    assert.equal(categoryName('science_and_tech'), 'Science and tech');
    assert.equal(categoryName('health-care'), 'Health care');
    assert.equal(categoryName(null), 'Other');
    assert.equal(categoryName('  '), 'Other');
});

test('sources are grouped by category, the groups alphabetical and each group in its own order', () => {
    const sources = [
        { id: 1, name: 'BBC World', category: 'world', hidden: false, policy_status: 'ok' },
        { id: 2, name: 'Nature', category: 'science', hidden: true, policy_status: 'warning' },
        { id: 3, name: 'Reuters', category: 'world', hidden: false, policy_status: 'ok' },
        { id: 4, name: 'Ars', category: 'technology', hidden: false, policy_status: 'checking' },
    ];

    const groups = groupByCategory(sources);

    assert.deepEqual(
        groups.map((group) => group.label),
        ['Science', 'Technology', 'World'],
    );
    assert.deepEqual(
        groups[2].sources.map((source) => source.id),
        [1, 3],
    );
});

test('no sources means no groups', () => {
    assert.deepEqual(groupByCategory([]), []);
    assert.deepEqual(groupByCategory(undefined), []);
});
