const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { build } = require('esbuild');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

(async () => {
    function SharedLeads(props) { return React.createElement('p', null, props.caption); }
    const education = 'portal/education/Leads';
    const sales = 'portal/sales/Leads';
    global.__navigationPages = {
        [`./pages/${education}.jsx`]: async () => ({ default: SharedLeads }),
        [`./pages/${sales}.jsx`]: async () => ({ default: SharedLeads }),
    };
    global.__navigationLayouts = Object.fromEntries(['EducationLayout', 'SalesLayout'].map(name => [name,
        ({ children }) => React.createElement('section', { 'data-portal': name }, children),
    ]));
    const contents = fs.readFileSync('resources/js/app.jsx', 'utf8')
        .replace("import.meta.glob('./pages/**/*.jsx')", 'global.__navigationPages')
        .replace(/import\('\.\/components\/layout\/([^']+)'\)/g,
            (_, name) => `Promise.resolve({ default: global.__navigationLayouts[${JSON.stringify(name)}] })`);
    const result = await build({
        stdin: { contents, loader: 'jsx', resolveDir: path.resolve('resources/js') },
        bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
        external: ['react', 'react/jsx-runtime'],
        plugins: [{ name: 'app-boundaries', setup(bundler) {
            bundler.onResolve({ filter: /.*/ }, args => {
                if (args.path === './lib/pageLayout') return { path: path.resolve('resources/js/lib/pageLayout.jsx') };
                if (args.path === 'react' || args.path.startsWith('react/')) return { path: args.path, external: true };
                return { path: args.path, namespace: 'boundary' };
            });
            bundler.onLoad({ filter: /.*/, namespace: 'boundary' }, args => ({ contents:
                args.path === '@inertiajs/react'
                    ? 'export const router = {}; export function createInertiaApp(options) { global.__navigationResolve = options.resolve; return Promise.resolve(); }'
                    : 'export default function Stub(){return null;} export const Toaster=Stub, ConfirmHost=Stub, installUserViewRequests=()=>{}, updateUserView=()=>{}, createRoot=()=>{};',
            }));
        } }],
    });
    const appModule = new Module(path.resolve('portal-layout-navigation-test.cjs'), module);
    appModule.filename = path.resolve('portal-layout-navigation-test.cjs');
    appModule.paths = module.paths;
    appModule._compile(result.outputFiles[0].text, appModule.filename);
    const resolve = global.__navigationResolve;
    const render = page => renderToStaticMarkup(page.default.layout(React.createElement(page.default, { caption: 'Lead content' })));

    const educationPage = await resolve(education);
    assert.match(render(educationPage), /data-portal="EducationLayout"/);
    const salesPage = await resolve(sales);
    assert.match(render(salesPage), /data-portal="SalesLayout"/, 'Sales must not retain the layout assigned through its Education re-export');
    assert.match(render(educationPage), /data-portal="EducationLayout"/, 'Resolving Sales must not alter the existing Education page');
    assert.equal(SharedLeads.layout, undefined, 'Automatic layouts must not mutate a shared page component');
    assert.equal((await resolve(sales)).default, salesPage.default, 'Repeat visits retain component identity for Inertia preserveState');
    assert.equal((await resolve(education)).default, educationPage.default);
    assert.match(render(salesPage), /Lead content/, 'The wrapper forwards the page props');

    function CustomPage() { return null; }
    CustomPage.layout = page => React.createElement('article', null, page);
    global.__navigationPages['./pages/portal/sales/Custom.jsx'] = async () => ({ default: CustomPage });
    assert.equal((await resolve('portal/sales/Custom')).default, CustomPage, 'Explicit page layouts are preserved');
    global.__navigationPages['./pages/public/Page.jsx'] = async () => ({ default: SharedLeads });
    assert.equal((await resolve('public/Page')).default, SharedLeads, 'Pages without automatic layouts remain unchanged');
    console.log('Shared-page portal layout isolation, stable identity and explicit layout tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
