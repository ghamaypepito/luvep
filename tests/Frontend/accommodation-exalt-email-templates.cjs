const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/accommodation/compose' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'KeyboardEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;

const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const posts = [];
const empty = () => null;
const mocks = {
    '@inertiajs/react': { Head: empty, router: { post: (...args) => posts.push(args) } },
    'lucide-react': new Proxy({}, { get: () => empty }),
    'unlayer-types': {},
};
const output = buildSync({
    entryPoints: ['resources/js/components/email/ExaltTemplateDraft.jsx'],
    alias: { '@': path.resolve('resources/js') }, bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', ...Object.keys(mocks)],
}).outputFiles[0].text;
const loaded = new Module(path.resolve('exalt-email-template-test.cjs'), module);
loaded.filename = path.resolve('exalt-email-template-test.cjs');
loaded.paths = module.paths;
loaded.require = (name) => mocks[name] ?? module.require(name);
loaded._compile(output, loaded.filename);

const Draft = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const props = {
    template: { id: 7, name: 'Move-In Cost Invoice Sent', exalt: {
        action: 'invoice', fields: [{ key: 'First Name' }, { key: 'Total Amount Due' }],
    } },
    basePath: '/portal/accommodation/compose',
    attachments: [],
    onSent() {},
    onClear() {},
};
const render = (email) => act(async () => root.render(React.createElement(Draft, { ...props, email })));
const change = (node, value) => act(async () => {
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value);
    node.dispatchEvent(new Event(node.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
});
const button = (label) => [...document.querySelectorAll('button')].find((element) => element.textContent === label);
let lookup;
let preview;
window.axios = {
    get: async (_url, config) => {
        lookup = config.params;
        return { data: { options: [{ id: 4, label: 'EXALT-MI-SAMPLE', fields: { 'Total Amount Due': 'NZ$1,245.00' } }] } };
    },
    post: async (url, data) => {
        preview = { url, data };
        return { data: { subject: 'Your move-in invoice | Test House', body: '<p>Sample preview</p>' } };
    },
};

(async () => {
    await render(null);
    assert.equal(button('Send email').disabled, true);
    await render('client@example.test');
    assert.deepEqual(lookup, { template_id: 7, email: 'client@example.test' });
    await change(document.querySelector('select'), '4');
    const inputs = document.querySelectorAll('input');
    assert.equal(inputs[1].value, 'NZ$1,245.00');
    assert.equal(inputs[1].readOnly, true);
    await change(inputs[0], 'Sample');
    await act(async () => button('Preview').click());
    assert.equal(preview.url, props.basePath + '/exalt-template/preview');
    assert.equal(preview.data.fields['First Name'], 'Sample');
    assert.equal(document.querySelector('iframe').srcdoc, '<p>Sample preview</p>');
    await act(async () => button('Send email').click());
    assert.equal(posts[0][0], props.basePath + '/exalt-template/send');
    assert.equal(posts[0][1].action_id, '4');
    assert.equal(posts[0][1].email, 'client@example.test');
    await act(async () => root.unmount());
    dom.window.close();
    console.log('Exalt email draft UI passed: single recipient, portal record selection, editable fields, preview and send.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
