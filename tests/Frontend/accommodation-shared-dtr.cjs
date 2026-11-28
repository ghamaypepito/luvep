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
const requests = [];
const useForm = (initial) => {
    const [data, setData] = React.useState(initial);
    return {
        data, errors: {}, processing: false,
        setData: (key, value) => setData({ ...data, [key]: value }),
        reset: () => setData(initial),
        post: (url) => requests.push({ method: 'POST', url, data }),
        patch: (url) => requests.push({ method: 'PATCH', url, data }),
    };
};
const compiled = buildSync({
    entryPoints: ['resources/js/pages/portal/accommodation/SharedDtrTasks.jsx'],
    bundle: true, write: false, format: 'cjs', jsx: 'automatic', platform: 'node',
    external: ['react', 'react/jsx-runtime', '@inertiajs/react'],
}).outputFiles[0].text;
const loaded = new Module(__filename, module);
loaded.paths = module.paths;
loaded.require = (name) => name === '@inertiajs/react' ? { useForm } : module.require(name);
loaded._compile(compiled, __filename);
const Panel = loaded.exports.default;
const root = createRoot(document.getElementById('root'));
const task = { id: 9, title: 'Arrange viewing', assignee_id: 1, version: 2, status: 'ongoing', created_at: '2026-11-25T03:00:00Z' };
const board = { members: [{ id: 1, name: 'Janille' }, { id: 2, name: 'Alaine' }], userId: 2, tasks: [task], completed: [] };

(async () => {
    await act(async () => root.render(React.createElement(Panel, { board })));
    const add = document.querySelector('form[aria-label="Add shared task"]');
    assert.equal(add.querySelector('[aria-label="Responsible person"]').value, '2');
    const edit = document.querySelector('form[aria-label="Edit Arrange viewing"]');
    await act(async () => edit.dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true })));
    assert.equal(requests.at(-1).method, 'PATCH');
    assert.equal(requests.at(-1).data.version, 2);
    await act(async () => [...edit.querySelectorAll('button')].find(button => button.textContent === 'Task done').click());
    assert.equal(requests.at(-1).url, '/portal/accommodation/dtr/shared-tasks/9/complete');
    await act(async () => root.render(React.createElement(Panel, { board: {
        ...board, tasks: [], completed: [{ ...task, completed_by_name: 'Alaine', completed_at: task.created_at }],
    } })));
    assert.equal(document.querySelector('strong').textContent, 'Done by Alaine');
    assert.match(document.body.textContent, /Assigned to Janille/);
    assert.equal(document.querySelector('form[aria-label="Edit Arrange viewing"]'), null);
    await act(async () => root.unmount());
    console.log('Shared DTR assignee, edit version, completion action and Done by badge passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
