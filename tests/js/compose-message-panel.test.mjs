import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';

const bundle = await build({
    stdin: {
        contents: `import React, {act} from 'react'; import {createRoot} from 'react-dom/client';
            import ComposeMessagePanel from './resources/js/components/sales/ComposeMessagePanel.jsx';
            window.IS_REACT_ACT_ENVIRONMENT=true; window.act=act;
            window.errors=[]; window.successes=[]; window.closed=0;
            const root=createRoot(document.getElementById('root'));
            window.renderPanel=(lead={id:42,name:'Synthetic lead'})=>act(async()=>root.render(<ComposeMessagePanel open lead={lead} onClose={()=>window.closed++}/>));
            window.unmountPanel=()=>act(()=>root.unmount());`,
        resolveDir: process.cwd(), loader: 'jsx',
    },
    bundle: true, write: false, format: 'iife', jsx: 'automatic',
    alias: { '@': resolve('resources/js') },
    plugins: [{ name: 'compose-toast', setup(builder) {
        builder.onResolve({ filter: /^sonner$/ }, () => ({ path: 'sonner', namespace: 'test' }));
        builder.onLoad({ filter: /.*/, namespace: 'test' }, () => ({ contents:
            'export const toast={error(message){window.errors.push(message)},success(message){window.successes.push(message)}};',
        }));
    } }],
});

const templates = [
    { id: 11, name: 'Email update', channels: ['email'] },
    { id: 12, name: 'SMS update', channels: ['sms'] },
    { id: 13, name: 'Dual update', channels: ['email', 'sms'] },
];

async function mount({ loadFailure = false, rejectSend = false } = {}) {
    const dom = new JSDOM('<div id="root"></div>', {
        url: 'https://compose.test/admin/leads/42', runScripts: 'outside-only',
    });
    const { window } = dom;
    // React's async act uses MessageChannel to flush pending tasks; JSDOM does
    // not supply it. Deliver its notifications on Node's real task queue.
    window.MessageChannel = class {
        port1 = { onmessage: null };
        port2 = { postMessage: () => setImmediate(() => this.port1.onmessage?.()) };
    };
    window.requestAnimationFrame = callback => { callback(); return 1; };
    window.cancelAnimationFrame = () => {};
    window.requests = [];
    window.fetch = async (url, options = {}) => {
        window.requests.push({ url, ...options });
        if (options.method === 'POST') {
            if (rejectSend) throw new Error('Synthetic network failure');
            return { ok: true, status: 200, json: async () => ({ count: 1 }) };
        }
        return loadFailure
            ? { ok: false, status: 403, json: async () => ({ message: 'Templates unavailable' }) }
            : { ok: true, status: 200, json: async () => templates };
    };
    window.eval(bundle.outputFiles[0].text);
    await window.renderPanel();
    return dom;
}

async function click(window, label) {
    const button = [...window.document.querySelectorAll('button')].find(button => button.textContent.trim() === label);
    assert.ok(button, `Expected a ${label} button`);
    await window.act(async () => button.click());
    return button;
}

async function fill(window, element, value) {
    assert.ok(element, 'Expected an editable field');
    await window.act(async () => {
        const setter = Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value').set;
        setter.call(element, value);
        element.dispatchEvent(new window.Event(element.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    });
}

function optionNames(window) {
    return [...window.document.querySelectorAll('select option')].map(option => option.textContent);
}

test('compose loads the authorized lead picker and offers only templates supporting the selected channels', async () => {
    const dom = await mount();
    try {
        const { window } = dom;
        assert.equal(window.requests[0].url, '/admin/leads/42/compose/templates');
        assert.deepEqual(optionNames(window), ['Custom message…', 'Email update', 'Dual update']);
        await fill(window, window.document.querySelector('select'), '11');
        await click(window, 'SMS');
        assert.equal(window.document.querySelector('select').value, '');
        assert.ok(window.document.querySelector('textarea'), 'Switching to an unsupported channel restores the custom composer');
        assert.deepEqual(optionNames(window), ['Custom message…', 'SMS update', 'Dual update']);
        await click(window, 'Both');
        assert.deepEqual(optionNames(window), ['Custom message…', 'Dual update']);
        await fill(window, window.document.querySelector('select'), '13');
        await click(window, 'Send');
        const request = window.requests.find(request => request.method === 'POST');
        assert.equal(request.url, '/admin/leads/42/compose');
        assert.deepEqual(JSON.parse(request.body), { channel: 'both', template_id: 13 });
    } finally { dom.window.unmountPanel(); dom.window.close(); }
});

test('template-load errors are visible and leave custom messaging available', async () => {
    const dom = await mount({ loadFailure: true });
    try {
        const { window } = dom;
        assert.match(window.document.querySelector('[role="alert"]')?.textContent || '', /Templates unavailable/);
        assert.equal(window.document.querySelector('select').disabled, true);
        await fill(window, window.document.querySelector('input[placeholder="Subject"]'), 'Custom subject');
        await fill(window, window.document.querySelector('textarea'), 'Custom body');
        await click(window, 'Send');
        assert.deepEqual(JSON.parse(window.requests.find(request => request.method === 'POST').body), {
            channel: 'email', subject: 'Custom subject', body: 'Custom body',
        });
    } finally { dom.window.unmountPanel(); dom.window.close(); }
});

test('network failure preserves the custom draft, reports failure and releases Send for retry', async () => {
    const dom = await mount({ rejectSend: true });
    try {
        const { window } = dom;
        await fill(window, window.document.querySelector('input[placeholder="Subject"]'), 'Keep subject');
        await fill(window, window.document.querySelector('textarea'), 'Keep draft');
        const button = await click(window, 'Send');
        assert.equal(button.disabled, false);
        assert.equal(window.document.querySelector('input[placeholder="Subject"]').value, 'Keep subject');
        assert.equal(window.document.querySelector('textarea').value, 'Keep draft');
        assert.match(window.errors[0] || '', /Could not send/);
        assert.equal(window.successes.length, 0);
        assert.equal(window.closed, 0);
    } finally { dom.window.unmountPanel(); dom.window.close(); }
});
