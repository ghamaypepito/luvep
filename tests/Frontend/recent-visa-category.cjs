const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/immigration/cases/7/profile?tab=documents' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
let role = 'immigration_manager', added = 0;
const posts = [];
const output = buildSync({ entryPoints: ['resources/js/components/immigration/case-profile/tabs/AddRecentVisaCategory.jsx'], bundle: true,
    write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('recent-visa-category-test.cjs'), module);
loaded.filename = path.resolve('recent-visa-category-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? { usePage: () => ({ props: { auth: { user: { role } } } }), router: { post: (...args) => posts.push(args) } } : module.require(name);
loaded._compile(output, loaded.filename);
const Add = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const render = (exists = false) => act(async () => root.render(React.createElement(Add, { leadId: 7, exists, onAdded: () => added++ })));
const click = () => act(async () => document.querySelector('button').click());
(async () => {
    await render();
    assert.match(document.body.textContent, /optional.*visible to this client/i);
    assert.equal(document.querySelector('button').textContent, 'Add Recent visa / eVisa category');
    await click();
    assert.equal(posts.length, 1);
    assert.equal(posts[0][0], '/portal/immigration/cases/7/documents/recent-visa-category');
    assert.deepEqual(posts[0][1], {});
    assert.ok(document.querySelector('button').disabled);
    await click();
    assert.equal(posts.length, 1, 'Prevent repeated requests while saving');
    assert.ok(posts[0][2].only.includes('checklist') && posts[0][2].only.includes('checklistProgress'));
    await act(async () => { posts[0][2].onError({ category: 'Could not add this category' }); posts[0][2].onFinish(); });
    assert.match(document.querySelector('[role=alert]').textContent, /Could not add this category/);
    assert.equal(added, 0);
    await click();
    await act(async () => { posts[1][2].onSuccess(); posts[1][2].onFinish(); });
    assert.equal(added, 1, 'Successful save reveals the new category in the parent');
    await render(true);
    assert.equal(document.getElementById('root').textContent, '', 'Control disappears when the checklist already contains the category');
    for (role of ['lead', 'agent', 'sub_agent', 'finance', 'education', 'sales', 'accommodation']) {
        await render();
        assert.equal(document.getElementById('root').textContent, '', `${role} receives no category action`);
    }
    for (role of ['immigration', 'immigration_adviser', 'admin', 'super_admin']) {
        await render();
        assert.ok(document.querySelector('button'), `${role} retains staff category access`);
    }
    await act(async () => root.unmount());

    // Exercise the real parent wiring too: the checklist prop determines when
    // the action disappears and the new category opens after a successful save.
    const parentOutput = buildSync({ entryPoints: ['resources/js/components/immigration/case-profile/tabs/DocumentsTab.jsx'], bundle: true,
        write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react-dom', 'react/jsx-runtime', '@/*', '@inertiajs/react', 'sonner', 'lucide-react', './AddRecentVisaCategory', './RecentVisaDialog', './OtherDocumentsUpload'] }).outputFiles[0].text;
    const parent = new Module(path.resolve('recent-visa-category-parent-test.cjs'), module);
    parent.filename = path.resolve('recent-visa-category-parent-test.cjs'); parent.paths = module.paths;
    const empty = () => null;
    role = 'immigration_manager';
    parent.require = name => {
        if (name === './AddRecentVisaCategory') return Add;
        if (name === '@inertiajs/react') return { usePage: () => ({ props: { auth: { user: { role } } }, url: '/portal/immigration/cases/7/profile' }), router: { post: (...args) => posts.push(args) } };
        if (name === '@/lib/caseRoutes') return { immBase: () => '/portal/immigration', leadBase: () => '/admin/leads', docDownloadUrl: () => '/download' };
        if (name === 'lucide-react') return new Proxy({}, { get: () => empty });
        if (name === 'sonner') return { toast: {} };
        if (name.startsWith('@/') || name.startsWith('./')) return empty;
        return module.require(name);
    };
    parent._compile(parentOutput, parent.filename);
    const tabRoot = createRoot(document.getElementById('root'));
    const Tab = parent.exports.default;
    const tab = items => act(async () => tabRoot.render(React.createElement(Tab, { lead: { id: 7 }, checklist: { items } })));
    const addButton = () => [...document.querySelectorAll('button')].find(button => button.textContent.trim() === 'Add Recent visa / eVisa category');
    await tab([]);
    assert.ok(addButton(), 'An empty checklist still offers the category action');
    const passport = { key: 'passport', label: 'Passport', required: true };
    await tab([passport]);
    assert.ok(addButton());
    await act(async () => addButton().click());
    const parentPost = posts.at(-1);
    await act(async () => { parentPost[2].onSuccess(); parentPost[2].onFinish(); });
    await tab([passport, { key: 'recent_visa', label: 'Recent visa / eVisa', category: 'Visa applications & decisions', required: false }]);
    assert.equal(addButton(), undefined, 'The refreshed checklist removes the parent action');
    assert.match(document.body.textContent, /Recent visa \/ eVisa/);
    assert.ok(document.querySelector('td[title="Collapse section"]'), 'The newly added category opens for staff');
    await act(async () => tabRoot.unmount());
    console.log('Recent visa category: explicit visibility copy, empty request, busy guard, errors/retry, refresh callback, existing-row removal and staff roles passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode = 1; });
