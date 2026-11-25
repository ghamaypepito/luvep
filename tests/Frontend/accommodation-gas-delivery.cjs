const assert = require('node:assert/strict');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

const compiled = buildSync({
    entryPoints: ['resources/js/pages/portal/accommodation/GasDelivery.jsx'],
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', 'react/jsx-runtime', '@inertiajs/react'],
}).outputFiles[0].text;
const loaded = new Module(__filename, module);
loaded.paths = module.paths;
loaded.require = (name) => name === '@inertiajs/react'
    ? { Head: () => null, useForm: (data) => ({ data, errors: {} }), router: {} }
    : module.require(name);
loaded._compile(compiled, __filename);
const GasDelivery = loaded.exports.default;
const render = (props) => new JSDOM(renderToStaticMarkup(React.createElement(GasDelivery, props))).window.document;

const document = render({
    property: { address: '31 Vazey Way, Hobsonville', legacy_purchase_date: '2026-04-09' },
    today: '2026-11-25',
    month: '2026-08',
    total_paid_cents: 15115,
    orders: [
        { id: 1, ordered_on: '2026-08-31', bottles: 2, paid_cents: 15105 },
        { id: 2, ordered_on: '2026-08-01', bottles: 1, paid_cents: 10 },
    ],
});
assert.match(document.body.textContent, /31 Vazey Way, Hobsonville/);
assert.match(document.body.textContent, /Previous purchase date on file: 2026-04-09/);
assert.equal(document.querySelector('input[type="month"]').value, '2026-08');
assert.equal(document.querySelector('input[type="date"]').max, '2026-11-25');
assert.equal(document.querySelector('strong').textContent, '$151.15');
assert.deepEqual([...document.querySelectorAll('tbody tr td:last-child')].map((cell) => cell.textContent), ['$151.05', '$0.10']);
assert.equal(render({ property: null }).querySelector('form'), null);
console.log('Gas tracker order form, legacy date, month and NZD totals passed.');
