const assert = require('node:assert/strict'), { buildSync } = require('esbuild'), { JSDOM } = require('jsdom'), Module = require('node:module'), path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/admin/content-calendar' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'KeyboardEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.requestAnimationFrame = cb => cb();
dom.window.HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', ''); this.querySelector('button')?.focus(); };
dom.window.HTMLDialogElement.prototype.close = function () { this.removeAttribute('open'); };
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const requests = [], reloads = [];
let responseMode = 'success', stored = null, closed = 0, saved = 0;
const account = { publishing_enabled: true, accounts: [{ selected: true, active: true, name: 'Luv, Ep' }] };
const axios = { isCancel: () => false, get: async url => ({ data: url.includes('/accounts') ? account : { post: stored } }), post: async (url, data) => {
    requests.push({ url, data });
    if (responseMode === 'lost-save' && url === '/social/marketing/posts') throw Error('Lost response');
    if (responseMode === 'validation' && url === '/social/marketing/posts') throw { response: { status: 422, data: { message: 'Check media.', errors: { media: ['Invalid media'] } } } };
    if (url === '/social/marketing/posts') {
        stored = { id: 'post-1', version: 1, campaign: data.get('campaign'), service: data.get('service'), caption: data.get('caption'), first_comment: data.get('first_comment'), post_type: data.get('post_type'), publish_now: data.get('publish_now') === '1', calendar_date: data.get('schedule_date'), calendar_time: data.get('schedule_time'), status: 'draft', media_url: null };
    } else {
        stored = { ...stored, version: stored.version + 1, status: url.endsWith('/submit') ? 'in_review' : url.endsWith('/approve') ? 'approved' : 'scheduled' };
        if (responseMode === 'uncertain' && url.endsWith('/schedule')) {
            stored.status = 'needs_reconciliation';
            throw { response: { status: 502, data: { post: stored, message: 'The result requires reconciliation. Do not retry.' } } };
        }
    }
    return { data: { post: stored } };
} };
const inertia = { Head: () => null, Link: ({ children, ...props }) => React.createElement('a', props, children), router: { reload: opts => { reloads.push(opts); opts?.onFinish?.(); } } };
function load(entry) {
    const output = buildSync({ entryPoints: [entry], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, loader: { '.css': 'empty' }, external: ['react', 'react/jsx-runtime', 'react-dom', '@inertiajs/react', 'axios'] }).outputFiles[0].text;
    const loaded = new Module(path.resolve('calendar-social-test.cjs'), module); loaded.filename = path.resolve('calendar-social-test.cjs'); loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? inertia : name === 'axios' ? axios : module.require(name); loaded._compile(output, loaded.filename); return loaded.exports.default;
}
const Social = load('resources/js/components/editorial/SocialPostDialog.jsx'), Calendar = load('resources/js/pages/admin/editorial/ContentCalendar.jsx');
const root = createRoot(document.getElementById('root'));
let generation = 0;
const render = async (props = {}) => act(async () => root.render(React.createElement(Social, { key: ++generation, initialDate: '2026-11-20', canPublish: false, onClose: () => closed++, onSaved: () => saved++, ...props })));
const button = text => [...document.querySelectorAll('button')].find(n => n.textContent.trim() === text);
const click = async node => { assert.ok(node, 'Button exists'); await act(async () => node.dispatchEvent(new MouseEvent('click', { bubbles: true }))); };
const field = text => [...document.querySelectorAll('label')].find(n => n.childNodes[0]?.textContent.trim() === text)?.querySelector('input,textarea,select');
const fill = async (node, value) => act(async () => {
    assert.ok(node);
    const proto = node.tagName === 'TEXTAREA' ? dom.window.HTMLTextAreaElement.prototype : dom.window.HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(proto, 'value').set.call(node, value); node.dispatchEvent(new Event('input', { bubbles: true }));
});
const approved = { id: 'post-1', version: 9, status: 'approved', campaign: 'Approved title', service: 'General', caption: 'Approved caption', first_comment: 'Saved first comment', post_type: 'post', publish_now: false, calendar_date: '2026-11-20', calendar_time: '09:00' };
(async () => {
    await render();
    assert.equal(field('Date · Manila').value, '2026-11-20');
    await fill(field('Post title'), 'New social brief'); await fill(field('Caption'), 'Caption preserved.');
    await click(button('Save & submit for review'));
    assert.equal(requests.at(-2).data.get('schedule_timezone'), 'Asia/Manila');
    assert.deepEqual(requests.at(-1).data, { version: 1 });
    assert.equal(requests.at(-1).url, '/social/marketing/posts/post-1/submit');
    assert.equal(button('Approve this version'), undefined);
    assert.equal(field('Caption').value, 'Caption preserved.');
    responseMode = 'validation'; await render(); await fill(field('Caption'), 'Keep this caption');
    await click(button('Save draft')); assert.equal(field('Caption').value, 'Keep this caption'); assert.ok(document.body.textContent.includes('Invalid media'));
    responseMode = 'lost-save'; await render(); await fill(field('Caption'), 'Possible saved caption');
    const before = requests.length; await click(button('Save draft'));
    assert.ok(document.body.textContent.includes('save result is unconfirmed'));
    assert.equal(button('Save draft'), undefined); assert.equal(requests.length, before + 1); assert.ok(saved > 0);
    responseMode = 'success'; stored = { ...approved }; await render({ postId: stored.id, canPublish: true });
    const noWrites = requests.length; await click(button('Schedule through GHL'));
    assert.equal(requests.length, noWrites, 'Opening confirmation never sends the post');
    assert.equal(document.activeElement.textContent, 'Cancel posting');
    await click(button('Confirm and send to GHL'));
    assert.deepEqual(requests.at(-1).data, { version: 9 }); assert.equal(button('Schedule through GHL'), undefined);
    stored = { ...approved }; responseMode = 'uncertain'; await render({ postId: stored.id, canPublish: true });
    await click(button('Schedule through GHL')); await click(button('Confirm and send to GHL'));
    assert.ok(document.body.textContent.includes('cannot be retried here')); assert.equal(button('Save draft'), undefined);
    responseMode = 'success';
    await act(async () => root.render(React.createElement(Calendar, { key: ++generation, month: '2026-11', topics: [], undated: [], canPublish: false })));
    const day = document.querySelector('[aria-label="Add content on Friday, November 20"]'); assert.ok(day);
    await click(day); assert.ok(document.querySelector('[aria-labelledby="content-type-title"]'));
    await click(document.querySelector('.calendar-type-choice.social')); assert.equal(field('Date · Manila').value, '2026-11-20');
    await click(button('Close')); assert.equal(document.activeElement, day);
    stored = { ...approved, id: '12345678-1234-1234-1234-123456789abc' };
    dom.window.history.replaceState({}, '', '/admin/content-calendar?social_post=' + stored.id);
    await act(async () => root.render(React.createElement(Calendar, { key: ++generation, month: '2026-11', topics: [], undated: [], canPublish: true })));
    assert.equal(field('Caption').value, stored.caption, 'Marketing workspace links open the saved calendar post');
    const source = require('node:fs').readFileSync('resources/social-workspace/marketing-workflow.js', 'utf8');
    const helper = source.slice(source.indexOf('function marketingCalendarOnly'), source.indexOf('function marketingEdit'));
    const vm = require('node:vm'), destinations = [], context = { window: { location: { assign: url => destinations.push(url) } }, encodeURIComponent };
    vm.createContext(context); vm.runInContext(helper, context);
    assert.equal(!!context.marketingCalendarOnly({ post_type: 'post' }), false);
    for (const extra of [{ post_type: 'reel' }, { first_comment: '0' }, { publish_now: true }, { media_mime: 'video/mp4' }]) assert.equal(context.marketingCalendarOnly(extra), true);
    context.marketingOpenCalendar(stored); assert.equal(destinations[0], '/admin/content-calendar?social_post=' + stored.id);
    await act(async () => root.unmount());
    console.log('Calendar social composer: date chooser, save/review payloads, draft retention, approval roles, confirmation, unknown-save lock, reconciliation and focus verified.');
})().catch(error => { console.error(error); process.exitCode = 1; });
