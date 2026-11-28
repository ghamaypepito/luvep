const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const { JSDOM } = require('jsdom');

const compiled = buildSync({
    stdin: {
        contents: `export { default as CaseHeader } from './resources/js/components/immigration/case-profile/CaseProfileHeader.jsx';
            export { default as Dashboard } from './resources/js/pages/portal/lead/Dashboard.jsx';`,
        resolveDir: process.cwd(), loader: 'jsx',
    },
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    alias: { '@': path.resolve('resources/js') },
    external: ['react', 'react/jsx-runtime', 'react-dom', '@inertiajs/react'],
}).outputFiles[0].text;
const file = path.resolve('client-full-name-test.cjs');
const loaded = new Module(file, module);
loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? {
    Head: () => null,
    Link: ({ href, className, children }) => React.createElement('a', { href, className }, children),
    usePage: () => ({ props: { auth: { user: { role: 'immigration_manager' } } } }),
} : module.require(name);
loaded._compile(compiled, file);

function render(component, lead) {
    return new JSDOM(renderToStaticMarkup(React.createElement(component, { lead }))).window.document;
}

const lead = { id: 1, lead_id: 'LP-EXAMPLE', first_name: 'Pat', middle_name: 'Renée', last_name: 'Patel', full_name: 'Pat Renée Patel' };
const { CaseHeader, Dashboard } = loaded.exports;
assert.equal(render(CaseHeader, lead).querySelector('h1').textContent, 'Pat Renée Patel');
assert.match(render(Dashboard, lead).body.textContent, /Full namePat Renée Patel/);
assert.equal(render(CaseHeader, { ...lead, middle_name: null, full_name: 'Pat Patel' }).querySelector('h1').textContent, 'Pat Patel');
assert.equal(render(CaseHeader, { id: 2, lead_id: 'LP-MISSING-NAME', full_name: '' }).querySelector('h1').textContent, 'LP-MISSING-NAME');
console.log('Client full-name UI passed: case heading, signed-in dashboard, and missing-name fallbacks.');
