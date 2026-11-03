const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
dom.window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
dom.window.HTMLDialogElement.prototype.close = function () { this.open = false; };
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
let auth = {}, deny = false, closed = 0;
const reads = [], posts = [];
const options = [
    { key: 'education', label: 'Student', stages: ['New Lead', 'Proposal Sent'], default_stage: 'New Lead' },
    { key: 'immigration', label: 'Immigration', stages: ['For Assessment'], default_stage: 'For Assessment' },
    { key: 'english', label: 'English', stages: ['PTE Review'], default_stage: 'PTE Review' },
    { key: 'accommodation', label: 'Accommodation', stages: [], default_stage: null },
].map(item => ({ ...item, active: false, assignees: [], open_url: null }));
const axios = { get: async (url) => { reads.push(url); if (deny) throw { response: { status: 403 } }; return { data: { pathways: options } }; } };
const inertia = {
    usePage: () => ({ props: { auth } }),
    Link: ({ children, ...props }) => React.createElement('a', props, children),
    useForm: initial => {
        const [data, set] = React.useState(initial);
        return { data, errors: {}, processing: false, clearErrors() {}, setData: (key, value) => set(old => typeof key === 'string' ? { ...old, [key]: value } : key), post: (url, callbacks) => posts.push({ url, data, callbacks }) };
    },
};
const source = fs.readFileSync('resources/js/pages/portal/sales/Leads.jsx', 'utf8');
const start = source.indexOf('function RowMenu('), end = source.indexOf('\n//', start);
// Execute the real menu and dialog without loading the unrelated full lead page.
const compiled = buildSync({ stdin: { contents: `import React, { useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom'; import { Link, usePage } from '@inertiajs/react';
import { MoreHorizontal, ExternalLink, Pencil, Plus, FileText, Paperclip, Copy, KeyRound, Clock, UserCheck } from 'lucide-react';
import { PathwayDialog } from '@/components/leads/AddPathwayButton';
const PORTAL_BADGE = {};
${source.slice(start, end)}
export { RowMenu };`, resolveDir: path.resolve('resources/js'), loader: 'jsx' }, bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, external: ['react', 'react-dom', '@inertiajs/react', 'axios'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('client-pathway-menu-test.cjs'), module); loaded.filename = path.resolve('client-pathway-menu-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : name === 'axios' ? axios : module.require(name); loaded._compile(compiled, loaded.filename);
function Harness() {
    const [open, setOpen] = React.useState(false);
    return React.createElement(loaded.exports.RowMenu, { lead: { id: 42 }, open, onToggle: () => setOpen(!open), onClose: () => { closed++; setOpen(false); }, canRequestInvite: false });
}
const root = createRoot(document.getElementById('root'));
const click = async node => { assert.ok(node, 'Control exists'); await act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true }))); };
const button = name => [...document.querySelectorAll('button')].find(node => node.textContent.trim() === name);
const render = async (role, granted) => { auth = { user: { role }, modules: granted ? ['client_pathways'] : [] }; await act(async () => root.render(React.createElement(Harness, { key: `${role}-${granted}` }))); await click(document.querySelector('[title="More actions"]')); };
(async () => {
    for (const role of ['education', 'immigration_manager']) {
        await render(role, false); assert.equal(button('Add pathway'), undefined);
        await render(role, true); await click(button('Add pathway'));
        assert.equal(document.querySelector('[role="menu"]'), null, 'Dropdown closes while the dialog stays mounted');
        assert.ok(document.querySelector('dialog[open]'));
        assert.equal(reads.at(-1), '/clients/42/pathways');
        assert.deepEqual([...document.querySelector('dialog select').options].map(item => item.text), ['Student', 'Immigration', 'English', 'Accommodation']);
        const select = document.querySelector('dialog select');
        await act(async () => { Object.getOwnPropertyDescriptor(dom.window.HTMLSelectElement.prototype, 'value').set.call(select, 'immigration'); select.dispatchEvent(new Event('change', { bubbles: true })); });
        assert.ok(button('Add Immigration pathway'));
        await act(async () => document.querySelector('dialog form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
        assert.equal(posts.at(-1).url, '/clients/42/pathways');
        assert.equal(posts.at(-1).data.pathway, 'immigration');
        assert.equal(posts.at(-1).data.stage, 'For Assessment');
        await act(async () => posts.at(-1).callbacks.onSuccess());
        assert.equal(document.querySelector('dialog'), null);
        assert.equal(document.activeElement, document.querySelector('[title="More actions"]'), 'Focus returns to the row trigger');
    }
    await render('lead', true); assert.equal(button('Add pathway'), undefined, 'External role does not gain a shortcut through a module payload');
    deny = true; await render('education', true); await click(button('Add pathway'));
    assert.match(document.querySelector('dialog [role="alert"]').textContent, /do not have permission/);
    assert.equal(document.querySelector('dialog form'), null, '403 cannot expose a submit control');
    assert.ok(closed > 0);
    await click(document.querySelector('[aria-label="Close pathways"]'));
    await act(async () => root.unmount());
    console.log('Pathway row-menu grants, persistent dialog, destinations, submission, focus and 403 regressions passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
