// node --test resources/js/lib/tabs.test.js
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isCurrentSection, isCurrentTab, PLATFORM_TABS, SETTINGS_TABS } from './tabs.js';

test('the settings tabs are Profile, AI, Sources and Usage, in that order', () => {
    assert.deepEqual(
        SETTINGS_TABS.map((tab) => tab.label),
        ['Profile', 'AI', 'Sources', 'Usage'],
    );
});

test('the platform tabs are the seven of the panel, and Jobs opens Horizon outside the app', () => {
    assert.deepEqual(
        PLATFORM_TABS.map((tab) => tab.label),
        ['Overview', 'Users', 'AI', 'Limits', 'Sources', 'Privacy', 'Jobs'],
    );
    assert.deepEqual(PLATFORM_TABS.at(-1), { label: 'Jobs', href: '/horizon', external: true });
});

test('a settings sub-page is its tab, and the profile is only the exact address', () => {
    assert.equal(isCurrentTab('/settings/ai', '/settings/ai'), true);
    assert.equal(isCurrentTab('/settings/ai?tab=models', '/settings/ai'), true);
    assert.equal(isCurrentTab('/settings/usage', '/settings/usage'), true);
    assert.equal(isCurrentTab('/settings', '/settings'), true);
    assert.equal(isCurrentTab('/settings/ai', '/settings'), false);
    assert.equal(isCurrentTab('/settings/ai', '/settings/usage'), false);
});

test('an admin sub-page is its tab, and the overview is only the exact address', () => {
    assert.equal(isCurrentTab('/admin/limits', '/admin/limits'), true);
    assert.equal(isCurrentTab('/admin', '/admin'), true);
    assert.equal(isCurrentTab('/admin/users', '/admin'), false);
    assert.equal(isCurrentTab('/admin/ai', '/admin/limits'), false);
});

test('a section of the menu is lit on every page of it', () => {
    assert.equal(isCurrentSection('/settings/usage', '/settings'), true);
    assert.equal(isCurrentSection('/settings', '/settings'), true);
    assert.equal(isCurrentSection('/admin/users', '/admin'), true);
    assert.equal(isCurrentSection('/feed', '/admin'), false);
    assert.equal(isCurrentSection('/settingsx', '/settings'), false);
    assert.equal(isCurrentSection(undefined, '/feed'), false);
});
