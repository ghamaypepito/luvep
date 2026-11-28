const assert = require('node:assert/strict');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const React = require('react');
const { createRoot } = require('react-dom/client');
const { act } = React;

const { window } = new JSDOM('<div id="root"></div>');
global.window = window;
global.document = window.document;
global.IS_REACT_ACT_ENVIRONMENT = true;
const useForm = (initial) => {
    const [data, update] = React.useState(initial);
    return { data, errors: {}, setData: (key, value) => update(current =>
        typeof key === 'function' ? key(current) : { ...current, [key]: value }) };
};
const compiled = buildSync({
    entryPoints: ['resources/js/pages/accommodation/PreTenancyForm.jsx'],
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', '@inertiajs/react'],
}).outputFiles[0].text;
const loaded = new Module(__filename, module);
loaded.paths = module.paths;
loaded.require = (name) => name === '@inertiajs/react' ? { Head: () => null, useForm } : module.require(name);
loaded._compile(compiled, __filename);
const root = createRoot(document.getElementById('root'));
const options = { welcome_packages: {
    kitchenware: { name: 'Kitchenware Package', price: 250, inclusions: ['Rice cooker'] },
    grocery: { name: 'Grocery Package', price: 150, inclusions: ['Eggs'] },
    bedding: { name: 'Bedding Essentials Package', price: 200, inclusions: ['Duvet'] },
} };

(async () => {
    await act(async () => root.render(React.createElement(loaded.exports.default, { token: 'general', general: true, options })));
    const section = [...document.querySelectorAll('section')].find(node => node.textContent.includes('Exalt Welcome Packages'));
    const select = (value) => act(async () => section.querySelector(`input[value="${value}"]`).click());
    assert.match(section.previousElementSibling.textContent, /Reference/);
    assert.match(section.nextElementSibling.textContent, /Submit form/);
    assert.equal(section.querySelectorAll('input[type=checkbox]').length, 0);
    await select('yes');
    const choices = [...section.querySelectorAll('input[type=checkbox]')];
    assert.equal(choices.length, 3);
    assert.match(section.textContent, /\$0 NZD/);
    await act(async () => choices[0].click());
    assert.match(section.querySelector('[aria-live]').textContent, /\$250 NZD/);
    await act(async () => choices[1].click());
    await act(async () => choices[2].click());
    assert.match(section.querySelector('[aria-live]').textContent, /\$600 NZD/);
    await select('no');
    assert.equal(section.querySelectorAll('input[type=checkbox]').length, 0);
    await select('yes');
    assert.equal(section.querySelectorAll('input[type=checkbox]:checked').length, 0);
    await act(async () => root.unmount());
    console.log('Welcome package section order, opt-in, individual selections, totals and reset passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
