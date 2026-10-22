// node --test resources/js/pages/Debate.test.js
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';

// The Vue compiler of this project, so the page's props are read as the build sees them.
const { parse, compileScript } = createRequire(import.meta.url)('vue/compiler-sfc');

function compiledProps(name) {
    const file = new URL(name, import.meta.url);
    const { descriptor } = parse(readFileSync(file, 'utf8'), { filename: file.pathname });
    const output = compileScript(descriptor, { id: name }).content;
    const literal = output.match(/props: (\{\n[\s\S]*?\n\}),\n {2}setup\(/);

    assert.ok(literal, 'the compiled page declares its props as an object');

    return new Function(`return ${literal[1]};`)();
}

test('a page without flow, brief or briefStatus is the classic one: no brief, no status', () => {
    const props = compiledProps('./Debate.vue');

    assert.equal(props.flow.default, 'classic');
    assert.equal(props.brief.default, null);
    assert.equal(props.briefStatus.default, null);
});

test('the props the page already had keep their shape, and the guided ones are optional', () => {
    const props = compiledProps('./Debate.vue');

    assert.equal(props.debate.required, true);
    assert.equal(props.article.required, true);
    assert.equal(props.messages.required, true);
    assert.equal(props.flow.required, undefined);
    assert.equal(props.brief.required, undefined);
    assert.equal(props.briefStatus.required, undefined);
});
