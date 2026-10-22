// node --test resources/js/pages/screens.test.js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const { parse, compileScript, compileTemplate } = createRequire(import.meta.url)('vue/compiler-sfc');

// The screens of the user's settings, the platform panel, the navigation and the guest pages. Each must compile:
// script, template and the template's own errors.
const SCREENS = [
    '../layouts/AppLayout.vue',
    '../layouts/GuestLayout.vue',
    '../components/AiLinks.vue',
    '../components/SectionTabs.vue',
    '../components/ProviderKeyCard.vue',
    '../components/AiTaskField.vue',
    '../components/SelectionLookup.vue',
    './Debate.vue',
    './Settings.vue',
    './Welcome.vue',
    './auth/Login.vue',
    './auth/RegistrationClosed.vue',
    './settings/Ai.vue',
    './settings/Sources.vue',
    './settings/Usage.vue',
    './admin/Index.vue',
    './admin/Ai.vue',
    './admin/Privacy.vue',
    './admin/Users.vue',
    './admin/Limits.vue',
    './admin/Sources.vue',
];

function parsed(name) {
    const file = new URL(name, import.meta.url);
    const { descriptor, errors } = parse(readFileSync(file, 'utf8'), { filename: file.pathname });

    return { file, descriptor, errors };
}

for (const name of SCREENS) {
    test(`${name} compiles`, () => {
        const { file, descriptor, errors } = parsed(name);

        assert.deepEqual(errors, []);
        assert.ok(descriptor.template, 'the screen has a template');
        assert.ok(compileScript(descriptor, { id: name }).content.length > 0);

        const { errors: templateErrors } = compileTemplate({ source: descriptor.template.content, filename: file.pathname, id: name });
        assert.deepEqual(templateErrors, []);
    });
}

// The declared props of a compiled screen, as the build reads them. The props object is followed by `emits` or `setup`.
function compiledProps(name) {
    const { descriptor } = parsed(name);
    const output = compileScript(descriptor, { id: name }).content;
    const literal = output.match(/props: (\{\n[\s\S]*?\n\}),\n {2}(?:emits: [^\n]*\n {2})?setup\(/);

    assert.ok(literal, `${name} declares its props as an object`);

    return new Function(`return ${literal[1]};`)();
}

// A default is either a value or a factory, as Vue reads it.
const defaultOf = (prop) => (typeof prop.default === 'function' ? prop.default() : prop.default);

test('the tab bar and the error links take their input from the caller, the links empty by default', () => {
    const tabs = compiledProps('../components/SectionTabs.vue');

    assert.equal(tabs.tabs.required, true);
    assert.equal(tabs.label.required, true);

    const links = compiledProps('../components/AiLinks.vue');

    assert.deepEqual(defaultOf(links.links), []);
});

test('the key card needs its provider, and the task field needs its task and value; the rest default to nothing', () => {
    assert.equal(compiledProps('../components/ProviderKeyCard.vue').provider.required, true);

    const field = compiledProps('../components/AiTaskField.vue');

    assert.equal(field.task.required, true);
    assert.equal(field.modelValue.required, true);
    assert.deepEqual(defaultOf(field.choices), []);
    assert.deepEqual(defaultOf(field.suggestions), {});
    assert.deepEqual(defaultOf(field.error), {});
});

test('the AI settings page starts empty: no providers, no tasks, no platform, no suggestions', () => {
    const props = compiledProps('./settings/Ai.vue');

    assert.deepEqual(defaultOf(props.providers), []);
    assert.deepEqual(defaultOf(props.tasks), {});
    assert.deepEqual(defaultOf(props.platform), { allowed: false });
    assert.deepEqual(defaultOf(props.suggestions), {});
});

test('the sources page of the user starts with no sources', () => {
    assert.deepEqual(defaultOf(compiledProps('./settings/Sources.vue').sources), []);
});

test('the usage page has no cap, no calls and no rows until the server sends them', () => {
    const props = compiledProps('./settings/Usage.vue');

    assert.equal(props.month.required, true);
    assert.equal(defaultOf(props.cap), null);
    assert.equal(defaultOf(props.used), 0);
    assert.deepEqual(defaultOf(props.by_task), []);
    assert.deepEqual(defaultOf(props.recent), []);
});

test('the platform overview needs its cards; the users page starts empty', () => {
    assert.equal(compiledProps('./admin/Index.vue').cards.required, true);
    assert.deepEqual(defaultOf(compiledProps('./admin/Users.vue').users), []);
});

test('the platform AI and privacy pages have the lines they need, empty by default', () => {
    const ai = compiledProps('./admin/Ai.vue');

    assert.deepEqual(defaultOf(ai.settings), {
        base_url: { value: null, default: null },
        api_key: { set: false, last4: null, source: null },
        models: {},
    });

    const privacy = compiledProps('./admin/Privacy.vue');

    assert.deepEqual(defaultOf(privacy.controller), { value: null, default: null });
    assert.deepEqual(defaultOf(privacy.contact_email), { value: null, default: null });
});

test('the limits page no longer takes the guided debate switches, whose route is gone', () => {
    const props = compiledProps('./admin/Limits.vue');

    assert.equal(props.limits.required, true);
    assert.equal(props.aiBudget.required, true);
    assert.equal(props.guidedDebate, undefined);
});
