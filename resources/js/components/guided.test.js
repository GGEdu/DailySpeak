// node --test resources/js/components/guided.test.js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const { parse, compileScript, compileTemplate } = createRequire(import.meta.url)('vue/compiler-sfc');

// The components of the guided debate must compile: script, template and the template's own errors.
for (const name of ['DebateBrief.vue', 'BriefStatus.vue', 'TurnCorrections.vue', 'ChatMessage.vue']) {
    test(`${name} compiles`, () => {
        const file = new URL(`./${name}`, import.meta.url);
        const { descriptor, errors } = parse(readFileSync(file, 'utf8'), { filename: file.pathname });

        assert.deepEqual(errors, []);
        assert.ok(descriptor.template, 'the component has a template');
        assert.ok(compileScript(descriptor, { id: name }).content.length > 0);

        const { errors: templateErrors } = compileTemplate({ source: descriptor.template.content, filename: file.pathname, id: name });
        assert.deepEqual(templateErrors, []);
    });
}
