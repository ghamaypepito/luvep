const assert = require('node:assert/strict');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const Module = require('node:module');
const path = require('node:path');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads/1' });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) global[key] = dom.window[key];
Object.defineProperty(global, 'navigator', { value: dom.window.navigator, configurable: true });
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'); const { act } = React; const { createRoot } = require('react-dom/client');
const output = buildSync({ entryPoints: ['resources/js/components/education/EducationAge.jsx'], bundle: true, write: false,
    format: 'cjs', platform: 'node', jsx: 'automatic', external: ['react', 'react/jsx-runtime'] }).outputFiles[0].text;
const loaded = new Module(path.resolve('education-age-test.cjs'), module); loaded.filename = path.resolve('education-age-test.cjs'); loaded.paths = module.paths; loaded._compile(output, loaded.filename);
const Age = loaded.exports.default, ageFromBirthDate = loaded.exports.ageFromBirthDate;
for (const [dob, today, expected] of [
    ['2018-03-08','2026-11-18',8], ['2000-11-18','2026-11-17',25], ['2000-11-18','2026-11-18',26], ['2000-11-18','2026-11-19',26],
    ['2004-02-29','2025-02-28',20], ['2004-02-29','2025-03-01',21], ['2026-11-17','2026-11-18',0],
    ['2026-11-18','2026-11-18',0], ['2026-11-19','2026-11-18',null], ['', '2026-11-18',null], [null,'2026-11-18',null],
    ['2026-02-30','2026-11-18',null], ['1900-02-29','2026-11-18',null], ['2026-13-01','2026-11-18',null],
    ['03/08/2018','2026-11-18',null], ['2018-03-08T00:00:00Z','2026-11-18',null], ['2018-03-08','bad',null],
]) assert.equal(ageFromBirthDate(dob,today),expected,`${dob} at ${today}`);
let writes = 0; global.fetch = async () => { writes++; throw Error('Age display must not write or request data'); };
function Form({ initialDob, savedAge, today }) {
    const [dob, setDob] = React.useState(initialDob);
    return React.createElement(React.Fragment, null,
        React.createElement('input', { type: 'date', 'aria-label': 'Date of birth', value: dob, onChange: e => setDob(e.target.value) }),
        React.createElement(Age, { dob, referenceDate: today, fallbackAge: !initialDob ? savedAge : null }),
        React.createElement('button', { onClick: () => setDob(initialDob) }, 'Cancel'));
}
const root = createRoot(document.getElementById('root'));
const change = async value => act(async () => { const input = document.querySelector('input'); Object.getOwnPropertyDescriptor(Object.getPrototypeOf(input),'value').set.call(input,value); input.dispatchEvent(new Event('input',{bubbles:true})); input.dispatchEvent(new Event('change',{bubbles:true})); });
(async () => {
    await act(async () => root.render(React.createElement(Form,{initialDob:'2018-03-08',savedAge:'',today:'2026-11-18'})));
    assert.equal(document.querySelector('output').textContent,'8');
    await change('2000-11-18'); assert.equal(document.querySelector('output').textContent,'26','Edited DOB updates without saving');
    await change('2026-11-17'); assert.equal(document.querySelector('output').textContent,'0','Age zero must remain visible');
    await change(''); assert.equal(document.querySelector('output').textContent,'-','Clearing DOB must not show the previous computed age');
    await act(async () => document.querySelector('button').click()); assert.equal(document.querySelector('output').textContent,'8','Cancel restores the original derived age');
    await act(async () => root.render(React.createElement(Form,{key:'legacy',initialDob:'',savedAge:40,today:'2026-11-18'})));
    assert.equal(document.querySelector('output').textContent,'40','Existing imported age remains available without DOB');
    await change('2000-11-19'); assert.equal(document.querySelector('output').textContent,'25','A valid edited DOB takes precedence over legacy age');
    await change('2026-11-19'); assert.equal(document.querySelector('output').textContent,'-','Invalid/future DOB must not fall back to imported age');
    assert.equal(document.querySelector('output').getAttribute('name'),null,'Computed age is not a submitted field');
    assert.equal(document.querySelector('output').getAttribute('aria-live'),'polite');
    assert.equal(document.querySelectorAll('input').length,1); assert.equal(writes,0);
    await act(async () => root.unmount());
    console.log('Education age: calendar/birthday/leap-year validation, live DOB edits, age zero, clear/cancel, legacy fallback and no computed-value writes passed.');
})().catch(async error => { console.error(error); await act(async () => root.unmount()); process.exitCode=1; });
