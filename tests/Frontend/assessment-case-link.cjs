const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const Module = require('node:module');
const path = require('node:path');
const React = require('react');
const { renderToStaticMarkup } = require('react-dom/server');
const source = buildSync({ entryPoints: ['resources/js/components/immigration/AssessmentCaseLink.jsx'],
    bundle: true, write: false, format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', '@inertiajs/react'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('assessment-case-link-test.cjs'), module);
loaded.filename = path.resolve('assessment-case-link-test.cjs'); loaded.paths = module.paths;
loaded.require = name => name === '@inertiajs/react'
    ? { Link: ({ href, children, ...props }) => React.createElement('a', { href, ...props }, children) } : module.require(name);
loaded._compile(source, loaded.filename);
const render = assessment => renderToStaticMarkup(React.createElement(loaded.exports.default, { assessment }));
assert.equal(render({}), '');
assert.match(render({ case_url: '/portal/immigration/cases/42/profile?tab=documents' }), /href="\/portal\/immigration\/cases\/42\/profile\?tab=documents"[^>]*>Open case/);
const missing = render({ conversion_issue: 'Marked converted, but no live case was found.' });
assert.match(missing, /role="alert"/);
assert.doesNotMatch(missing, /href=|Open case/);
const escaped = render({ conversion_issue: '<script>not executable</script>' });
assert.doesNotMatch(escaped, /<script>/);
console.log('Assessment case link checks passed: live destination, missing-case warning and escaped text.');
