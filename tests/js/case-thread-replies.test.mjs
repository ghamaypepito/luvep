import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';

// Keep the components, hooks and DOM events real; replace only the network and
// toast boundaries so every post remains synthetic and its callbacks controllable.
const bundle = await build({
    stdin: {
        contents: `import React, {act} from 'react';
            import {createRoot} from 'react-dom/client';
            import {ThreadItem} from './resources/js/components/immigration/case-profile/threads.jsx';
            window.IS_REACT_ACT_ENVIRONMENT = true;
            window.act = act;
            window.calls = []; window.errors = [];
            const root = createRoot(document.getElementById('root'));
            window.renderThread = props => act(() => root.render(<ThreadItem {...props}/>));
            window.unmountThread = () => act(() => root.unmount());`,
        resolveDir: process.cwd(), loader: 'jsx',
    },
    bundle: true, write: false, format: 'iife', jsx: 'automatic',
    alias: { '@': resolve('resources/js') },
    plugins: [{
        name: 'thread-transport',
        setup(builder) {
            builder.onResolve({ filter: /^(@inertiajs\/react|sonner)$/ }, args => ({ path: args.path, namespace: 'test' }));
            builder.onLoad({ filter: /.*/, namespace: 'test' }, args => ({
                contents: args.path === '@inertiajs/react'
                    ? 'export const router = {post(url,data,options){window.calls.push({url,data,options});}};'
                    : 'export const toast = {success(){},error(message){window.errors.push(message);}};',
            }));
        },
    }],
});

function mount(props) {
    const dom = new JSDOM('<div id="root"></div>', {
        url: 'https://thread.test/portal/immigration/cases/42/profile', runScripts: 'outside-only',
    });
    dom.window.eval(bundle.outputFiles[0].text);
    dom.window.renderThread({
        leadId: 42,
        ...props,
        thread: {
            id: 71, parent_id: null, author: 'Case worker', author_role: 'immigration',
            body: 'Original question', client_visible: false, anchor_type: 'case',
            anchor_id: null, anchor_key: null, anchor_attempt: null, ...props.thread,
        },
    });
    return dom;
}

function click(window, label, index = 0) {
    const button = [...window.document.querySelectorAll('button')].filter(button => button.textContent.trim() === label)[index];
    assert.ok(button, `Expected a ${label} button`);
    window.act(() => button.click());
}

function typeReply(window, text) {
    const textarea = window.document.querySelector('textarea[placeholder="Write a reply…"]');
    assert.ok(textarea, 'Reply must open a child composer');
    window.act(() => {
        const setter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set;
        setter.call(textarea, text);
        textarea.dispatchEvent(new window.Event('input', { bubbles: true }));
    });
    return textarea;
}

const anchors = [
    { name: 'case', thread: {}, want: { anchor_type: 'case', anchor_id: null, anchor_key: null, anchor_attempt: null } },
    { name: 'gate', thread: { anchor_type: 'gate', anchor_key: '06' }, want: { anchor_type: 'gate', anchor_id: null, anchor_key: '06', anchor_attempt: null } },
    { name: 'stage', thread: { anchor_type: 'stage', anchor_key: 'Evidence review' }, want: { anchor_type: 'stage', anchor_id: null, anchor_key: 'Evidence review', anchor_attempt: null } },
    { name: 'step', thread: { anchor_type: 'step', anchor_key: 'passport', anchor_attempt: 2 }, want: { anchor_type: 'step', anchor_id: null, anchor_key: 'passport', anchor_attempt: 2 } },
    { name: 'document', thread: { anchor_type: 'document', anchor_id: 19 }, want: { anchor_type: 'document', anchor_id: 19, anchor_key: null, anchor_attempt: null } },
    { name: 'checklist', thread: { anchor_type: 'checklist', anchor_key: 'police_cert' }, want: { anchor_type: 'checklist', anchor_id: null, anchor_key: 'police_cert', anchor_attempt: null } },
    { name: 'reviewer note', thread: { anchor_type: 'reviewer_note', anchor_id: 19 }, want: { anchor_type: 'reviewer_note', anchor_id: 19, anchor_key: null, anchor_attempt: null } },
];

for (const fixture of anchors) {
    test(`Reply opens a child composer and posts the persisted ${fixture.name} anchor`, () => {
        const dom = mount({ thread: fixture.thread });
        try {
            const { window } = dom;
            click(window, 'Reply');
            typeReply(window, 'Synthetic answer');
            click(window, 'Post reply');
            assert.equal(window.calls.length, 1);
            const { url, data } = window.calls[0];
            assert.equal(url, '/portal/immigration/cases/42/threads');
            assert.deepEqual(JSON.parse(JSON.stringify(data)), {
                ...fixture.want, parent_id: 71, body: 'Synthetic answer', client_visible: false,
            });
        } finally { dom.window.unmountThread(); dom.window.close(); }
    });
}

test('document replies keep the explicit anchor, parent and client audience on general lead routes', () => {
    const dom = mount({
        thread: { client_visible: true },
        anchor: { anchor_type: 'document', anchor_id: 19 }, basePath: '/admin/leads',
    });
    try {
        const { window } = dom;
        click(window, 'Reply');
        typeReply(window, 'Client answer');
        click(window, 'Post reply');
        assert.equal(window.calls[0].url, '/admin/leads/42/threads');
        assert.deepEqual(JSON.parse(JSON.stringify(window.calls[0].data)), {
            anchor_type: 'document', anchor_id: 19, parent_id: 71, body: 'Client answer', client_visible: true,
        });
    } finally { dom.window.unmountThread(); dom.window.close(); }
});

test('a reply to a nested comment uses that comment as parent and inherits its step attempt', () => {
    const dom = mount({
        thread: { anchor_type: 'step', anchor_key: 'passport', anchor_attempt: 2 },
        childrenOf: id => id === 71 ? [{
            id: 72, parent_id: 71, body: 'First answer', author: 'Second worker', client_visible: false,
            anchor_type: 'step', anchor_key: 'passport', anchor_attempt: 2,
        }] : [],
    });
    try {
        const { window } = dom;
        click(window, 'Reply', 1);
        typeReply(window, 'Follow-up answer');
        click(window, 'Post reply');
        assert.deepEqual(JSON.parse(JSON.stringify(window.calls[0].data)), {
            anchor_type: 'step', anchor_id: null, anchor_key: 'passport', anchor_attempt: 2,
            parent_id: 72, body: 'Follow-up answer', client_visible: false,
        });
    } finally { dom.window.unmountThread(); dom.window.close(); }
});

test('failed replies preserve the draft and can be retried; success closes the composer', () => {
    const dom = mount({ anchor: { anchor_type: 'case' } });
    try {
        const { window } = dom;
        click(window, 'Reply');
        const textarea = typeReply(window, 'Keep this answer');
        click(window, 'Post reply');
        const button = [...window.document.querySelectorAll('button')].find(button => button.textContent === 'Post reply');
        assert.equal(button.disabled, true);
        window.act(() => {
            window.calls[0].options.onError({ body: 'Synthetic failure' });
            window.calls[0].options.onFinish();
        });
        assert.equal(textarea.value, 'Keep this answer');
        assert.equal(button.disabled, false);
        assert.equal(window.errors[0], 'Synthetic failure');
        click(window, 'Post reply');
        assert.equal(window.calls[1].data.body, 'Keep this answer');
        window.act(() => {
            window.calls[1].options.onSuccess();
            window.calls[1].options.onFinish();
        });
        assert.equal(window.document.querySelector('textarea[placeholder="Write a reply…"]'), null);
    } finally { dom.window.unmountThread(); dom.window.close(); }
});
