const assert = require('node:assert/strict');
const path = require('node:path'), Module = require('node:module');
const { build } = require('esbuild'), { JSDOM } = require('jsdom');
const React = require('react'), { renderToStaticMarkup } = require('react-dom/server');
const dom = new JSDOM('', { url: 'https://luvep.test/admin/media-drive' });
global.window = dom.window; global.document = dom.window.document;
let url = '/admin/media-drive', role = 'super_admin';
const inertia = { Link: ({ children, ...props }) => React.createElement('a', props, children), router: {}, usePage: () => ({ url, props: { auth: { user: { role, name: 'Synthetic admin' }, modules: [] }, hr: {} } }) };
(async () => {
const compiled = (await build({ entryPoints: ['resources/js/components/layout/DashboardLayout.jsx'], bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', alias: { '@': path.resolve('resources/js') }, external: ['react', 'react-dom', '@inertiajs/react'], loader: { '.css': 'empty' }, plugins: [{ name: 'shell-boundaries', setup(build) {
    build.onResolve({ filter: /(?:UserViewControls|ProfileMenu|PreviewBanner|ClockWidget|NotificationBell|GlobalSearchBar|RequestTicketButton|Avatar)$/ }, args => ({ path: args.path, namespace: 'stub' }));
    build.onLoad({ filter: /.*/, namespace: 'stub' }, () => ({ contents: 'export default function Stub(){return null}; export const UserViewButton=Stub, AvatarPhoto=Stub;' }));
} }] })).outputFiles[0].text;
const loaded = new Module(path.resolve('media-workspace-test.cjs'), module); loaded.filename = path.resolve('media-workspace-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react' ? inertia : module.require(name); loaded._compile(compiled, loaded.filename);
const Shell = loaded.exports.default;
const render = (brand = 'Existing portal brand') => renderToStaticMarkup(React.createElement(Shell, { brand, nav: [{ name: 'Dashboard', href: '/admin/dashboard' }], footerNav: [{ name: 'Settings shortcut', href: '/admin/settings' }] }, React.createElement('h1', null, 'Media Drive')));
let markup = render();
assert.match(markup, /media-drive-workspace/); assert.match(markup, /src="\/images\/ep-logo.png"/); assert.match(markup, /alt="EP"/);
assert.match(markup, /href="\/admin\/media-drive" aria-current="page"/);
assert.doesNotMatch(markup, /Existing portal brand/);
url = '/admin/media-drive?folder=42'; assert.match(render(), /media-drive-workspace/);
url = '/portal/finance/media-drive'; role = 'finance'; assert.match(render(), /media-drive-workspace/);
url = '/admin/dashboard'; role = 'super_admin'; markup = render();
assert.doesNotMatch(markup, /media-drive-workspace/); assert.doesNotMatch(markup, /src="\/images\/ep-logo.png"/); assert.match(markup, /Existing portal brand/);
url = '/admin/leads'; markup = render('EP.');
assert.match(markup, /src="\/images\/ep-logo.png"/); assert.match(markup, /alt="EP"/); assert.doesNotMatch(markup, /media-drive-workspace/);
url = '/portal/lead/media-drive'; role = 'lead'; assert.doesNotMatch(render(), /media-drive-workspace/, 'The invited-client experience retains its existing shell');
dom.window.close(); console.log('Media workspace: scoped EP shell/logo, active navigation, staff portals, footer navigation and non-Drive/client isolation passed.');

})().catch(error => { console.error(error); dom.window.close(); process.exitCode = 1; });
