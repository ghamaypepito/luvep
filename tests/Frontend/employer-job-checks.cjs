const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');

const basePath = '/portal/immigration/employer-job-checks';
const dom = new JSDOM('<div id="root"></div>', { url: `https://luvep.test${basePath}` });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;

const React = require('react');
const { createRoot } = require('react-dom/client');
const requests = [];
const inertia = {
    Head: () => null,
    Link: ({ href, children }) => React.createElement('a', { href }, children),
    router: { get: (url, data) => requests.push({ method: 'get', url, data }) },
    useForm(initial) {
        const [data, setData] = React.useState(initial);
        return {
            data,
            setData: (key, value) => setData(previous => ({ ...previous, [key]: value })),
            errors: {},
            post: url => requests.push({ method: 'post', url, data }),
            patch: url => requests.push({ method: 'patch', url, data }),
        };
    },
};

const file = path.resolve('resources/js/pages/portal/immigration/EmployerJobChecks.jsx');
const compiled = buildSync({
    entryPoints: [file], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', '@inertiajs/react'],
}).outputFiles[0].text;
const loaded = new Module(file, module);
loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name);
loaded._compile(compiled, file);
const root = createRoot(document.getElementById('root'));

function field(name) {
    return [...document.querySelectorAll('label')]
        .find(label => label.firstElementChild.textContent === name)
        ?.querySelector('input, select');
}

async function change(name, value) {
    const input = field(name);
    const select = input.tagName === 'SELECT';
    const prototype = select ? dom.window.HTMLSelectElement.prototype : dom.window.HTMLInputElement.prototype;
    await React.act(async () => {
        Object.getOwnPropertyDescriptor(prototype, 'value').set.call(input, value);
        input.dispatchEvent(new Event(select ? 'change' : 'input', { bubbles: true }));
    });
}

async function submit(name) {
    const button = [...document.querySelectorAll('button')].find(item => item.textContent === name);
    await React.act(async () => button.form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
}

async function render(props) {
    await React.act(async () => root.render(React.createElement(loaded.exports.default, props)));
}

(async () => {
    const employer = { id: 2, legal_name: 'Example Employer', employer_identifier: 'TEST-02' };
    const jobCheck = { id: 15, employer, role_title: 'Cook', stage: 'intake', assigned_to: 7, details: {} };
    const stages = { intake: 'Intake', advertising: 'Advertising' };
    await render({ cases: { data: [jobCheck] }, employers: [employer], staff: [{ id: 7, name: 'Case manager' }], canManage: true, stages, search: '' });
    assert.equal(document.querySelector(`a[href="${basePath}/15"]`).textContent, 'Cook');
    await change('Search by employer or role', 'Cook');
    await submit('Search');
    assert.deepEqual(requests.pop(), { method: 'get', url: basePath, data: { search: 'Cook' } });

    await change('Employer', '2');
    await change('Role title', 'Chef');
    await change('Assigned to', '7');
    await submit('Create Job Check');
    assert.deepEqual(requests.pop(), { method: 'post', url: basePath, data: { employer_id: '2', role_title: 'Chef', assigned_to: '7' } });

    await render({ jobCheck, canManage: false, staff: [], stages });
    assert.equal(field('Assigned to'), undefined);
    assert.doesNotMatch(document.body.textContent, /Edit employer/);
    await change('Stage', 'advertising');
    await change('Advertisement start date', '2027-01-01');
    await change('Advertisement closing date', '2027-01-21');
    await submit('Save Job Check');
    assert.deepEqual(requests.pop(), {
        method: 'patch', url: `${basePath}/15`,
        data: { role_title: 'Cook', stage: 'advertising', details: { ad_starts_on: '2027-01-01', ad_closes_on: '2027-01-21' } },
    });
    await React.act(async () => root.unmount());
    console.log('PASS: Employer Job Check list, search, create, assigned-staff controls, and detail save.');
})().catch(error => { console.error(error); process.exitCode = 1; });
