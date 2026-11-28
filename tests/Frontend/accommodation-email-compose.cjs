const assert = require('node:assert/strict');
const { build } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/accommodation/compose' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'FormData']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { createRoot } = require('react-dom/client');

const calls = [];
const noop = () => null;
const router = {
    get: (...args) => calls.push(['get', ...args]),
    post: (...args) => calls.push(['post', ...args]),
    reload: (...args) => calls.push(['reload', ...args]),
};
global.fetch = async url => {
    const endpoint = new URL(url, dom.window.location.href);
    calls.push(['fetch', endpoint]);
    return {
        ok: true,
        json: async () => ({ contacts: [{
            id: 'owner:1', name: 'Sample Owner', email: 'owner@example.test', property: '10 Example Road',
        }] }),
    };
};

const file = path.resolve('resources/js/pages/portal/accommodation/Compose.jsx');
const compilation = build({
    entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    alias: { '@': path.resolve('resources/js') },
    external: ['react', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react'],
    plugins: [{
        name: 'stub-editor',
        setup(build) {
            build.onResolve({ filter: /\/(RichTextEditor|EmailBuilder)(\.jsx)?$/ }, args => ({ path: args.path, external: true }));
        },
    }],
});
const page = new Module(file, module);
page.paths = module.paths;
page.require = name => {
    if (name === '@inertiajs/react') return { Head: noop, router };
    if (/RichTextEditor(?:\.jsx)?$/.test(name)) {
        return ({ value, onChange }) => React.createElement('textarea', {
            'aria-label': 'Message', value, onChange: event => onChange(event.target.value),
        });
    }
    if (/EmailBuilder(?:\.jsx)?$/.test(name)) return noop;
    return module.require(name);
};

const basePath = '/portal/accommodation/compose';
const root = createRoot(document.getElementById('root'));
const button = (label, container = document) => [...container.querySelectorAll('button')].find(node => node.textContent.trim().includes(label));
const click = node => React.act(async () => { assert.ok(node); node.click(); });
const change = (node, value) => React.act(async () => {
    Object.getOwnPropertyDescriptor(Object.getPrototypeOf(node), 'value').set.call(node, value);
    node.dispatchEvent(new Event('input', { bubbles: true }));
});
const waitForSearch = () => React.act(async () => new Promise(resolve => setTimeout(resolve, 330)));

(async () => {
    page._compile((await compilation).outputFiles[0].text, file);
    const attachmentUrl = `${basePath}/sent/3/attachments/0`;
    const sent = [{
        id: 3, subject: 'Viewing confirmation', body: '<p>The viewing is at 11 am.</p>',
        to: 'tenant@example.test', to_name: 'Sample Tenant', from_name: 'Exalt Accommodation', from_email: 'exalt@example.test',
        sender: 'Accommodation Staff', created_at: '2026-11-26T21:30:00Z', sent_at: '2026-11-26T21:31:00Z', status: 'sent',
        attachments: [{ name: 'Viewing details.pdf', url: attachmentUrl }],
    }];
    await React.act(async () => root.render(React.createElement(page.exports.default, { basePath, sent })));
    const history = document.querySelector('aside');
    await click(button('Viewing confirmation', history));
    assert.match(document.querySelector('main').textContent, /tenant@example.test.*exalt@example.test.*Accommodation Staff.*NZ time/);
    assert.match(document.querySelector('iframe[title="Sent email"]').srcdoc, /at 11 am/);
    assert.equal(document.querySelector(`a[href="${attachmentUrl}"]`).textContent, 'Viewing details.pdf');
    await change(history.querySelector('input[type="search"]'), 'Viewing');
    await waitForSearch();
    assert.deepEqual(calls.find(([name]) => name === 'get').slice(0, 3), ['get', basePath, { sent_search: 'Viewing' }]);

    await click(button('Property Owners'));
    await change(document.querySelector('input[aria-label="Search accommodation contacts"]'), 'owner@example.test');
    await waitForSearch();
    const [, endpoint] = calls.filter(([name]) => name === 'fetch').at(-1);
    assert.equal(endpoint.pathname, `${basePath}/contacts`);
    assert.equal(endpoint.searchParams.get('type'), 'owner');
    assert.equal(endpoint.searchParams.get('q'), 'owner@example.test');
    const contact = button('Sample Owner');
    assert.match(contact.textContent, /10 Example Road/);
    await click(contact);
    await change(document.querySelector('input[placeholder="Subject"]'), 'Viewing');
    await change(document.querySelector('textarea[aria-label="Message"]'), 'Message body');
    await click(button('Send', document.querySelector('main')));
    const [, sendUrl, payload] = calls.find(([name]) => name === 'post');
    assert.equal(sendUrl, `${basePath}/send`);
    assert.equal(payload.get('contact_keys[]'), 'owner:1');
    assert.equal(payload.get('contact_addresses[owner:1]'), 'owner@example.test');
    assert.equal(payload.get('lead_ids[]'), null);
    await React.act(async () => root.unmount());
    dom.window.close();
    console.log('Accommodation Compose UI passed: contact tabs, email search, property labels, scoped send, and Sent search and details.');
})().catch(error => { console.error(error); process.exitCode = 1; });
