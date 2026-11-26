import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';

const bundle = await build({ stdin: { contents: `
import React,{act,useState} from 'react';
import {createRoot} from 'react-dom/client';
import Fields,{VifSupplementEditor} from './resources/js/components/visa/VifSupplementFields.jsx';
window.IS_REACT_ACT_ENVIRONMENT=true;window.act=act;
const root=createRoot(document.getElementById('root'));
function App({type,initial}){const[values,setValues]=useState(initial);window.values=values;return <Fields type={type} values={values} onChange={(key,value)=>setValues(old=>({...old,[key]:value}))}/>;}
window.editor=(type,values,saveUrl='/synthetic-vif')=>act(()=>root.render(<VifSupplementEditor type={type} values={values} saveUrl={saveUrl} csrfHeaders={{}} onSaved={fresh=>{window.savedCount=(window.savedCount||0)+1;window.fresh=fresh}}/>));
window.fields=(type,initial)=>act(()=>root.render(<App type={type} initial={initial}/>));
window.unmount=()=>act(()=>root.unmount());`, resolveDir: process.cwd(), loader: 'jsx' }, bundle: true, write: false, format: 'iife', jsx: 'automatic', alias: { '@': resolve('resources/js') } });

async function mount(type, initial, editor = true) {
    const dom = new JSDOM('<div id="root"></div>', { url: 'https://vif.test', runScripts: 'outside-only' });
    dom.window.MessageChannel = class { port1 = { onmessage: null }; port2 = { postMessage: () => setImmediate(() => this.port1.onmessage?.()) }; };
    dom.window.eval(bundle.outputFiles[0].text);
    await dom.window[editor ? 'editor' : 'fields'](type, initial);
    return dom;
}
function input(window, label, index = 0) {
    const labels = [...window.document.querySelectorAll('label')].filter(el => el.textContent === label);
    assert.ok(labels[index], 'Expected field '+label);
    return window.document.getElementById(labels[index].htmlFor);
}
async function fill(window, element, value) {
    await window.act(() => {
        Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value').set.call(element, value);
        element.dispatchEvent(new window.Event(element.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
    });
}
async function save(window) {
    const button = [...window.document.querySelectorAll('button')].find(el => el.textContent.trim() === 'Save additional answers');
    assert.ok(button);
    await window.act(() => button.click());
}
async function click(window, label) {
    const button = [...window.document.querySelectorAll('button')].find(el => el.textContent.trim() === label);
    assert.ok(button, 'Expected '+label);
    await window.act(() => button.click());
}
async function close(dom) { await dom.window.unmount(); dom.window.close(); }

test('unchanged staff saves and reverted edits never contact the backend or notify the parent', async () => {
    const values = { has_nz_contacts: 'No', vif_answers: { middle_name: 'Original' } };
    const dom = await mount('visitor', values);
    try {
        const w = dom.window; let requests = 0;
        w.fetch = async () => { requests++; return { ok: true, json: async () => ({ sections: [] }) }; };
        await save(w);
        assert.equal(requests, 0);
        assert.equal(w.savedCount || 0, 0);
        await fill(w, input(w, 'Middle name(s) as shown in your passport'), 'Temporary edit');
        await fill(w, input(w, 'Middle name(s) as shown in your passport'), 'Original');
        await save(w);
        assert.equal(requests, 0);
        assert.equal(w.savedCount || 0, 0);
    } finally { await close(dom); }
});

test('blank answers and adding then removing an empty list entry remain no-op saves', async () => {
    for (const initial of [{}, { vif_answers: { middle_name: null } }]) {
        const dom = await mount('visitor', initial);
        try {
            const w = dom.window; let requests = 0;
            w.fetch = async () => { requests++; return { ok: true, json: async () => ({ sections: [] }) }; };
            await fill(w, input(w, 'Middle name(s) as shown in your passport'), 'Temporary');
            await fill(w, input(w, 'Middle name(s) as shown in your passport'), '');
            await click(w, 'Add new zealand contacts');
            await click(w, 'Remove entry 1');
            await save(w);
            assert.equal(requests, 0);
            assert.equal(w.savedCount || 0, 0);
        } finally { await close(dom); }
    }
});

test('a changed nested answer omits stale siblings and family context and adopts the fresh saved baseline', async () => {
    const initial = { applying_as: 'Dependent child', visa_type: 'Residence class visa', vif_answers: { middle_name: 'Old middle', supporting_parent_nz_status: 'Old staff answer' } };
    const dom = await mount('family', initial);
    try {
        const w = dom.window; const requests = [];
        const freshValues = { applying_as: 'Partner', visa_type: 'Visitor visa', vif_answers: { supporting_parent_nz_status: 'Fresh server answer' } };
        w.fetch = async (_url, options) => { requests.push(JSON.parse(options.body)); return { ok: true, json: async () => ({ sections: [], vif_values: freshValues }) }; };
        await fill(w, input(w, 'Middle name(s) as shown in your passport'), 'Changed middle');
        await save(w);
        assert.deepEqual(requests, [{ fields: { vif_answers: { middle_name: 'Changed middle' } } }]);
        assert.equal(w.savedCount, 1);
        await save(w);
        assert.equal(requests.length, 1, 'A successful save establishes the returned values as the next baseline');
        assert.equal(w.savedCount, 1);
    } finally { await close(dom); }
});

test('a changed root answer does not resubmit untouched nested answers or context', async () => {
    const dom = await mount('visitor', { applying_as: 'Old context', visa_type: 'Old visa', has_nz_contacts: 'No', vif_answers: { middle_name: 'Old sibling' } });
    try {
        const w = dom.window; let payload;
        w.fetch = async (_url, options) => { payload = JSON.parse(options.body); return { ok: true, json: async () => ({ sections: [] }) }; };
        await fill(w, input(w, 'Do you have any contacts in New Zealand?'), 'Yes');
        await save(w);
        assert.deepEqual(payload, { fields: { has_nz_contacts: 'Yes' } });
    } finally { await close(dom); }
});

test('clearing a legacy qualification removes only its aliases and remains empty in the reopen/export payload', async () => {
    const second = { title: 'Second legacy qualification', provider: 'Second legacy school', retained: 'Other row' };
    const initial = { vif_answers: { middle_name: 'Untouched sibling' }, qualifications: [{ name: 'Legacy qualification', major: 'Stale major', title: 'Stale title', school: 'Legacy school', from: '2010-01-01', retained: 'Unknown legacy column' }, second] };
    const dom = await mount('student', initial);
    try {
        const w = dom.window; let payload;
        w.fetch = async (_url, options) => { payload = JSON.parse(options.body); return { ok: true, json: async () => ({ sections: [], vif_values: { ...initial, qualifications: payload.fields.qualifications } }) }; };
        assert.equal(input(w, 'Qualification or programme').value, 'Legacy qualification');
        await fill(w, input(w, 'Qualification or programme'), '');
        assert.equal(input(w, 'Qualification or programme').value, '', 'The old name alias must not resurrect while editing');
        await save(w);
        assert.deepEqual(payload, { fields: { qualifications: [{ qualification: '', school: 'Legacy school', from: '2010-01-01', retained: 'Unknown legacy column' }, second] } });
        await w.editor('student', { ...initial, qualifications: payload.fields.qualifications });
        assert.equal(input(w, 'Qualification or programme').value, '');
        assert.equal(input(w, 'Institution').value, 'Legacy school');
        assert.equal(input(w, 'Qualification or programme', 1).value, 'Second legacy qualification');
    } finally { await close(dom); }
});

test('explicit empty or null canonical values suppress legacy aliases before any edit', async () => {
    for (const value of ['', null]) {
        const dom = await mount('student', { qualifications: [{ qualification: value, name: 'Ignored old alias', school: 'Keep institution' }] }, false);
        try {
            assert.equal(input(dom.window, 'Qualification or programme').value, '');
            assert.equal(input(dom.window, 'Institution').value, 'Keep institution');
        } finally { await close(dom); }
    }
});

test('an unrelated staff section refresh keeps unsaved additional answers and adopts fresh siblings', async () => {
    const initial = { has_nz_contacts: 'No', vif_answers: { middle_name: 'Original' } };
    const dom = await mount('visitor', initial);
    try {
        const w = dom.window; const requests = [];
        await fill(w, input(w, 'Middle name(s) as shown in your passport'), 'Unsaved middle');
        const fresh = { has_nz_contacts: 'Yes', vif_answers: { middle_name: 'Original' } };
        await w.editor('visitor', fresh);
        assert.equal(input(w, 'Middle name(s) as shown in your passport').value, 'Unsaved middle');
        assert.equal(input(w, 'Do you have any contacts in New Zealand?').value, 'Yes');
        w.fetch = async (_url, options) => {
            requests.push(JSON.parse(options.body));
            return { ok: true, json: async () => ({ sections: [], vif_values: { ...fresh, vif_answers: { middle_name: 'Unsaved middle' } } }) };
        };
        await save(w);
        assert.deepEqual(requests, [{ fields: { vif_answers: { middle_name: 'Unsaved middle' } } }]);
        await save(w);
        assert.equal(requests.length, 1, 'Own successful save establishes the canonical baseline');
    } finally { await close(dom); }
});

test('fresh family context drops all newly hidden child-only edits without submitting them', async () => {
    const dom = await mount('family', { applying_as: 'Dependent child', visa_type: 'Residence class visa', vif_answers: { middle_name: 'Original', supporting_parent_nz_status: 'Original parent' } });
    try {
        const w = dom.window; let requests = 0;
        await fill(w, input(w, 'Middle name(s) as shown in your passport'), 'Local middle');
        await fill(w, input(w, 'Parent’s New Zealand citizenship or residence status'), 'Local parent');
        const fresh = { applying_as: 'Partner', visa_type: 'Visitor visa', vif_answers: { middle_name: 'Original' } };
        await w.editor('family', fresh);
        assert.equal(w.document.querySelectorAll('label').length, 0);
        assert.ok(![...w.document.querySelectorAll('label')].some(el => el.textContent.includes('Parent’s New Zealand citizenship')));
        w.fetch = async () => { requests++; return { ok: true, json: async () => ({ sections: [], vif_values: fresh }) }; };
        await save(w);
        assert.equal(requests, 0);
    } finally { await close(dom); }
});

test('a section refresh retains a locally edited repeatable list without resubmitting fresh sibling answers', async () => {
    const initial = { qualifications: [{ qualification: 'Original course', school: 'Original school' }], vif_answers: { middle_name: 'Original' } };
    const dom = await mount('student', initial);
    try {
        const w = dom.window; let payload;
        await fill(w, input(w, 'Qualification or programme'), 'Local course');
        await w.editor('student', { ...initial, vif_answers: { middle_name: 'Fresh server middle' } });
        assert.equal(input(w, 'Qualification or programme').value, 'Local course');
        assert.equal(input(w, 'Middle name(s) as shown in your passport').value, 'Fresh server middle');
        w.fetch = async (_url, options) => { payload = JSON.parse(options.body); return { ok: true, json: async () => ({ sections: [] }) }; };
        await save(w);
        assert.deepEqual(payload, { fields: { qualifications: [{ qualification: 'Local course', school: 'Original school' }] } });
    } finally { await close(dom); }
});

test('switching intake resets drafts and ignores a previous intake save response', async () => {
    const dom = await mount('visitor', { vif_answers: { middle_name: 'Original' } });
    try {
        const w = dom.window; let resolveSave;
        w.fetch = () => new Promise(resolve => { resolveSave = resolve; });
        await fill(w, input(w, 'Middle name(s) as shown in your passport'), 'Old intake edit');
        await save(w);
        await w.editor('visitor', { vif_answers: { middle_name: 'Other intake' } }, '/other-intake-vif');
        assert.equal(input(w, 'Middle name(s) as shown in your passport').value, 'Other intake');
        assert.equal([...w.document.querySelectorAll('button')].find(el => el.textContent === 'Save additional answers')?.disabled, false);
        await w.editor('visitor', { vif_answers: { middle_name: 'Reopened intake' } });
        await w.act(async () => { resolveSave({ ok: true, json: async () => ({ sections: [], vif_values: { vif_answers: { middle_name: 'Old intake edit' } } }) }); });
        assert.equal(input(w, 'Middle name(s) as shown in your passport').value, 'Reopened intake');
        assert.equal(w.savedCount || 0, 0);
    } finally { await close(dom); }
});
