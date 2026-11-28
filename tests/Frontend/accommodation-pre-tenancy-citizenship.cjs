const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const { JSDOM } = require('jsdom');

const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/apply/pre-tenancy' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'File']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { createRoot } = require('react-dom/client');

const posts = [];
const inertia = {
    Head: () => null,
    useForm(initial) {
        const [data, updateData] = React.useState(initial);
        const [errors, setErrors] = React.useState({ visa: 'Upload a visa first.' });
        return {
            data,
            errors,
            setData: (key, value) => updateData(typeof key === 'function' ? key : previous => ({ ...previous, [key]: value })),
            clearErrors: key => setErrors(previous => Object.fromEntries(Object.entries(previous).filter(([name]) => name !== key))),
            post: url => posts.push({ url, data }),
        };
    },
};
const file = path.resolve('resources/js/pages/accommodation/PreTenancyForm.jsx');
const compiled = buildSync({
    entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', '@inertiajs/react', 'lucide-react'],
}).outputFiles[0].text;
const page = new Module(file, module);
page.paths = module.paths;
page.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
page._compile(compiled, file);

const citizen = 'New Zealand citizen';
const visaType = 'Permanent Resident Visa';
const root = createRoot(document.getElementById('root'));
const input = id => document.getElementById(id);
const select = value => React.act(async () => {
    const choice = input('applicant-visa-type');
    choice.value = value;
    choice.dispatchEvent(new Event('change', { bubbles: true }));
});
const upload = (id, name) => React.act(async () => {
    const field = input(id);
    Object.defineProperty(field, 'files', { value: [new File(['test'], name, { type: 'application/pdf' })] });
    field.dispatchEvent(new Event('change', { bubbles: true }));
});

(async () => {
    await React.act(async () => root.render(React.createElement(page.exports.default, {
        token: 'general', general: true, citizenshipOption: citizen, options: { visa_types: [visaType] },
    })));
    assert.equal(document.querySelector('label[for="applicant-visa-type"]').textContent.trim(), 'Citizenship or Visa Type');
    assert.deepEqual([...input('applicant-visa-type').options].map(option => option.value), ['', citizen, visaType]);
    await select(visaType);
    await upload('visa', 'visa.pdf');
    await upload('valid_id', 'id.pdf');

    await select(citizen);
    assert.equal(input('visa'), null);
    assert.ok(input('valid_id'));
    await React.act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(posts[0].url, '/apply/pre-tenancy');
    assert.equal(posts[0].data.visa_type, citizen);
    assert.equal(posts[0].data.visa, null);
    assert.equal(posts[0].data.valid_id.name, 'id.pdf');

    await select(visaType);
    assert.ok(input('visa'));
    assert.doesNotMatch(document.body.textContent, /visa\.pdf|Upload a visa first/);
    assert.match(document.body.textContent, /id\.pdf/);
    await React.act(async () => root.unmount());
    dom.window.close();
    console.log('Pre-Tenancy citizenship UI passed: option, conditional upload, retained ID and cleared visa file.');
})().catch(error => { console.error(error); process.exitCode = 1; });
