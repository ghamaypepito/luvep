const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');

global.window = { location: { href: 'https://luvep.com/portal/hr/dashboard', origin: 'https://luvep.com' } };
const source = buildSync({
    entryPoints: ['resources/js/components/hr/HrReturnLink.jsx'],
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic',
    external: ['react', '@inertiajs/react'],
}).outputFiles[0].text;
const componentModule = new Module(path.resolve('hr-return-link-test.cjs'), module);
componentModule.filename = path.resolve('hr-return-link-test.cjs');
componentModule.paths = module.paths;
componentModule.require = (name) => name === '@inertiajs/react'
    ? { Link: ({ children, ...props }) => React.createElement('a', { ...props, 'data-inertia': 'true' }, children) }
    : module.require(name);
componentModule._compile(source, componentModule.filename);
const ReturnLink = componentModule.exports.default;
const render = (hr) => renderToStaticMarkup(React.createElement(ReturnLink, { hr }));

let html = render({ admin_return_url: 'https://luvep.com/admin/dashboard', main_url: 'https://luvep.com' });
assert.match(html, /href="https:\/\/luvep.com\/admin\/dashboard"/);
assert.match(html, /Back to Admin/);
assert.match(html, /data-inertia="true"/, 'Same-origin HR returns use app navigation');

window.location = { href: 'https://hr.luvep.com/dashboard', origin: 'https://hr.luvep.com' };
html = render({ admin_return_url: 'https://luvep.com/admin/dashboard' });
assert.match(html, /Back to Admin/);
assert.doesNotMatch(html, /data-inertia/, 'Cross-host returns use a normal link');

html = render({ main_url: 'https://luvep.com' });
assert.match(html, /href="https:\/\/luvep.com"/);
assert.match(html, /Back to Luvep/);
assert.doesNotMatch(html, /data-inertia/);
console.log('HR Admin return and cross-origin navigation tests passed');

let hrProps = {enabled:true,url:'https://hr.luvep.com/',permissions:{preview:true}};
const profileSource=buildSync({entryPoints:['resources/js/components/hr/ProfileMenu.jsx'],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:['react','@inertiajs/react']}).outputFiles[0].text;
const profileModule=new Module(path.resolve('hr-profile-menu-test.cjs'),module);
profileModule.filename=path.resolve('hr-profile-menu-test.cjs');profileModule.paths=module.paths;
profileModule.require=name=>name==='@inertiajs/react'?{usePage:()=>({props:{hr:hrProps,auth:{user:{name:'Alex'}}}}),Link:({children,...props})=>React.createElement('a',{...props,'data-inertia':'true'},children)}:module.require(name);
profileModule._compile(profileSource,profileModule.filename);
html=renderToStaticMarkup(React.createElement(profileModule.exports.default));
assert.equal((html.match(/data-inertia="true"/g)||[]).length,2,'Both internal profile routes preserve the clock provider');
hrProps={...hrProps,url:'https://luvep.com/portal/hr/'};
html=renderToStaticMarkup(React.createElement(profileModule.exports.default));
assert.doesNotMatch(html,/data-inertia/,'Cross-origin profile navigation keeps normal links');
console.log('HR profile navigation preserves the clock session on same-origin routes.');
