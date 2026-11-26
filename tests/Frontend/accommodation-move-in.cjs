const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/accommodation/tenants/7' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
dom.window.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
dom.window.HTMLDialogElement.prototype.close = function () { this.open = false; };
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const posts = []; let formData;
const inertia = { Head: () => null, Link: ({ children, ...props }) => React.createElement('a', props, children), router: {},
    useForm: initial => {
        const [data, setData] = React.useState(initial); formData = data;
        const transform = React.useRef(value => value);
        return { data, errors: {}, processing: false,
            setData: (key, value) => setData(typeof key === 'function' ? key : old => ({ ...old, [key]: value })),
            transform: callback => { transform.current = callback; },
            post: (url, options) => posts.push({ url, data: transform.current(data), options }),
        };
    },
};
function load(file) {
    const source = fs.readFileSync(file, 'utf8').replace("import MediaAttachButton from '@/components/media/MediaAttachButton';", 'const MediaAttachButton = () => null;');
    const output = buildSync({ stdin: { contents: source, resolveDir: path.dirname(path.resolve(file)), loader: 'jsx' }, bundle: true, write: false,
        platform: 'node', format: 'cjs', jsx: 'automatic', external: ['react', 'react/jsx-runtime', '@inertiajs/react'] }).outputFiles[0].text;
    const loaded = new Module(path.resolve('move-in-test.cjs'), module); loaded.filename = path.resolve('move-in-test.cjs'); loaded.paths = module.paths;
    loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
    loaded._compile(output, loaded.filename); return loaded.exports.default;
}
const Convert = load('resources/js/components/onboarding/ConvertTenantModal.jsx');
const Detail = load('resources/js/pages/portal/accommodation/TenantDetail.jsx');
const root = createRoot(document.getElementById('root'));
const button = label => [...document.querySelectorAll('button')].find(el => el.textContent.trim() === label);
const click = el => act(async () => { assert.ok(el); el.click(); });
const tenant = { id: 7, property_id: 3, first_name: 'Synthetic', family_name: 'Tenant', display_name: 'Synthetic Tenant',
    move_in_version: 'a'.repeat(64),
    current_status: 'pending_payment', email: 'signed@example.test', phone: 'signed-phone', contract_type: 'fixed_term',
    contract_start: '2026-11-18', contract_end: '2026-12-17', bond_paid_nzd: '700.00', advance_paid_nzd: '1400.00' };
(async () => {
    await act(async () => root.render(React.createElement(Convert, { submission: { id: 5, full_legal_name: 'Source Name', email: 'source@example.test', mobile: 'source-phone', converted_tenant: tenant }, onClose() {} })));
    for (const key of ['email', 'phone', 'bond_paid_nzd', 'advance_paid_nzd']) assert.equal(formData[key], tenant[key], `Preserve ${key}`);
    assert.equal(formData.expected_tenant_version, tenant.move_in_version);
    assert.ok([...document.querySelectorAll('input')].some(el => el.value === '700.00'));
    const rooms = [{ id: 'single', name: 'Single, shared bathroom' }, { id: 'ensuite', name: 'Ensuite' }];
    await act(async () => root.render(React.createElement(Convert, { key: 'assign', submission: {
        id: 5, full_legal_name: 'Source Name', converted_tenant: { ...tenant, assigned_room_ids: ['single'] },
    }, properties: [{ id: 3, address: 'Synthetic property', rooms }], onClose() {} })));
    const roomFields = document.querySelector('fieldset');
    assert.equal(roomFields.querySelector('legend').textContent, 'Assigned rooms');
    assert.deepEqual([...roomFields.querySelectorAll('input')].map(input => input.checked), [true, false]);
    await click([...roomFields.querySelectorAll('input')][1]);
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.deepEqual(posts.at(-1).data.assigned_room_ids, ['single', 'ensuite']);
    assert.equal(posts.at(-1).url, '/portal/accommodation/applications/5/convert');
    await act(async () => root.render(React.createElement(Convert, { key: 'unpaid', submission: { id: 6, full_legal_name: 'Synthetic Tenant', invoice_amount_nzd: '9999.00' }, onClose() {} })));
    assert.equal(formData.bond_paid_nzd, '', 'An invoice is not evidence of payment');
    await act(async () => root.render(React.createElement(Detail, { tenant, canActivate: true, properties: [{ id: 3, rooms }] })));
    await click(button('Complete payment review'));
    assert.ok(document.querySelector('[role="dialog"][aria-label="Complete payment review"]'));
    assert.equal(button('Activate tenant').disabled, true);
    await click(document.querySelector('[role="dialog"] fieldset input[type="checkbox"]'));
    await click([...document.querySelectorAll('[role="dialog"] input[type="checkbox"]')].find(input => !input.closest('fieldset')));
    assert.equal(button('Activate tenant').disabled, false);
    await act(async () => document.querySelector('[role="dialog"] form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(posts.at(-1).url, '/portal/accommodation/tenants/7/activate');
    assert.equal(posts.at(-1).data.payment_confirmed, true);
    assert.equal(posts.at(-1).data.room_assignment_version, tenant.move_in_version);
    assert.deepEqual(posts.at(-1).data.assigned_room_ids, ['single']);
    await click(button('Cancel'));
    await act(async () => root.render(React.createElement(Detail, { tenant, canActivate: false, onboardingUrl: '/portal/accommodation/applications/5' })));
    assert.equal(button('Complete payment review'), undefined);
    assert.equal(document.querySelector('a[href="/portal/accommodation/applications/5"]').textContent, 'Complete move-in in Onboarding');
    await act(async () => root.unmount());
    console.log('Accommodation move-in: preserves payments/contact, no invoice-as-payment, explicit standalone confirmation, correct endpoint and onboarding-only routing passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
