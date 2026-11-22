const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://synthetic.test/portal/education/leads' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const { renderToStaticMarkup } = require('react-dom/server');
const esbuild = require('esbuild');

function load(relative, extra, inertia, bundleLocal = false) {
    const file = path.resolve(relative);
    const output = esbuild.buildSync({
        stdin: { contents: fs.readFileSync(file, 'utf8') + extra, resolveDir: path.dirname(file), loader: 'jsx' },
        bundle: true, write: false, platform: 'node', format: 'cjs', jsx: 'automatic', packages: 'external',
        loader: { '.css': 'empty' },
        ...(bundleLocal ? { alias: { '@': path.resolve('resources/js') } } : { external: ['@/*', '@assets/*'] }),
    }).outputFiles[0].text;
    const mod = new Module(file, module);
    mod.filename = file;
    mod.paths = Module._nodeModulePaths(process.cwd());
    mod.require = (id) => id === '@inertiajs/react' ? inertia
        : id.startsWith('@assets') ? null : id.endsWith('.css') ? {} : id.startsWith('@/') ? () => null : require(id);
    mod._compile(output, file);
    return mod.exports;
}

async function main() {
    const errors = { occupation: 'The occupation field must not be greater than 255 characters.',
        'cv_files.0': 'The cv_files.0 field must be a file of type: pdf, doc, docx, xls, csv, jpg, jpeg, png, gif.',
        error: 'Registration could not be saved. Please try again.' };
    const quick = load('resources/js/pages/register/QuickRegisterPage.jsx', '', {
        useForm: (data) => ({ data, errors, setData() {}, post() {} }), Head: () => null,
    });
    const markup = renderToStaticMarkup(React.createElement(quick.default));
    const quickDoc = new JSDOM(markup).window.document;
    assert(quickDoc.querySelector('[role="alert"]'));
    for (const message of Object.values(errors)) assert(quickDoc.body.textContent.includes(message));

    let options, posted;
    const staff = load('resources/js/pages/portal/sales/Leads.jsx', '\nexport { AddLeadButton };', {
        router: { post: (url, data, handlers) => { posted = data; options = handlers; } },
        usePage: () => ({ props: { auth: { modules: [] } } }),
    });
    let root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(staff.AddLeadButton, { portalBase: '/portal/education' })));
    await act(async () => document.querySelector('button').click());
    const name = document.querySelector('form input');
    await act(async () => {
        Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(name, 'Synthetic applicant');
        name.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const form = () => document.querySelector('form');
    await act(async () => form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(posted.first_name, 'Synthetic applicant');
    await act(async () => { options.onSuccess({ props: { flash: { error: errors.error } } }); options.onFinish(); });
    assert(form()); assert.equal(document.querySelector('form input').value, 'Synthetic applicant');
    assert(document.querySelector('[role="alert"]').textContent.includes(errors.error));
    await act(async () => form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    await act(async () => { options.onError({ email: 'Use a valid email address.' }); options.onFinish(); });
    assert(form()); assert.equal(document.querySelector('form input').value, 'Synthetic applicant');
    assert(document.querySelector('[role="alert"]').textContent.includes('Use a valid email address.'));
    await act(async () => form().dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    await act(async () => { options.onSuccess({ props: { flash: { success: 'Added' } } }); options.onFinish(); });
    assert.equal(form(), null);
    await act(async () => root.unmount());

    const panel = load('resources/js/pages/admin/LeadDetails.jsx', '\nexport { DocumentsPanel };', {
        usePage: () => ({ props: { auth: { modules: [] } } }), Link: 'a', router: {},
    }, true);
    const doc = (id, name) => ({ id, original_name: name, download_url: '/admin/documents/' + id + '/download' });
    const payload = { lead: { id: 1, is_immigration_case: false }, education: true,
        checklistFiles: { cv: [doc(1, 'Legacy CV.pdf')], 'acad.cv': [doc(2, 'Current CV.pdf')],
            'visa.hidden': [doc(3, 'Prior immigration.pdf')] },
        orphans: [doc(1, 'Legacy CV.pdf'), doc(2, 'Current CV.pdf')] };
    const panelDoc = new JSDOM(renderToStaticMarkup(React.createElement(panel.DocumentsPanel, payload))).window.document;
    for (const filename of ['Legacy CV.pdf', 'Prior immigration.pdf']) {
        assert(panelDoc.body.textContent.includes(filename), filename + ' must remain visible');
    }
    const picker = [...panelDoc.querySelectorAll('button')].find((button) => button.textContent.includes('Download all'));
    assert(picker && !picker.disabled);
    assert.equal((panelDoc.body.textContent.match(/Legacy CV.pdf/g) || []).length, 1);
    assert.equal((panelDoc.body.textContent.match(/Current CV.pdf/g) || []).length, 0, 'Canonical file stays in its collapsible requirement');
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(panel.DocumentsPanel, payload)));
    await act(async () => [...document.querySelectorAll('button')].find((button) => button.textContent.includes('Download all')).click());
    const chooser = document.querySelector('[role="dialog"]') || document.body;
    assert(chooser.textContent.includes('Current CV.pdf'));
    assert(chooser.textContent.includes('Legacy CV.pdf'));
    assert(chooser.textContent.includes('Prior immigration.pdf'));
    const checked = [...chooser.querySelectorAll('input[type="checkbox"]')].filter((input) => input.checked);
    assert.equal(checked.length, 4, 'Three documents and the select-all control, without duplicate IDs');
    await act(async () => root.unmount());

    let visit;
    const folders = load('resources/js/pages/portal/education/Documents.jsx', '', {
        Head: () => null, Link: ({ href, children }) => React.createElement('a', { href }, children),
        usePage: () => ({ url: '/portal/education/documents?view=folders' }),
        router: { get: (url, data) => { visit = { url, data }; } },
    });
    root = createRoot(document.getElementById('root'));
    await act(async () => root.render(React.createElement(folders.default, {
        folders: [{ id: 201, name: 'Applicant200 Synthetic', lead_id: 'LP-00201', total: 1, approved: 1 }],
        folderFilters: { search: '', status: 'All' }, folderPagination: { from: 1, to: 40, total: 201, next: '/portal/education/documents?view=folders&page=2' },
    })));
    const search = document.querySelector('[aria-label="Search students"]');
    await act(async () => {
        Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set.call(search, 'Applicant200');
        search.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await act(async () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })));
    assert.deepEqual(visit.data, { view: 'folders', search: 'Applicant200', folder_status: 'All' });
    const next = [...document.querySelectorAll('a')].find((link) => link.textContent === 'Next');
    assert(next.href.includes('view=folders&page=2'));
    const complete = [...document.querySelectorAll('button')].find((button) => button.textContent === 'Complete');
    await act(async () => complete.click());
    assert.equal(visit.data.folder_status, 'Complete');
    await act(async () => root.unmount());
    console.log('Education intake recovery: public errors, staff retry, legacy files and folder search passed.');
}
main().catch((error) => { console.error(error); process.exitCode = 1; });
