import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';

const bundle = await build({ stdin: { contents: `import React,{act}from'react';import{createRoot}from'react-dom/client';import Page from'./resources/js/pages/portal/lead/VisaAssessment.jsx';window.IS_REACT_ACT_ENVIRONMENT=true;window.act=act;const root=createRoot(document.getElementById('root'));window.renderProps=assessment=>root.render(<Page vif={{available:true,generated:false}} assessment={assessment}/>);window.renderClient=assessment=>act(()=>window.renderProps(assessment));window.unmount=()=>act(()=>root.unmount());`, resolveDir: process.cwd(), loader: 'jsx' }, bundle: true, write: false, format: 'iife', jsx: 'automatic', alias: { '@': resolve('resources/js') }, plugins: [{ name: 'fresh-client-response', setup(builder) {
    builder.onResolve({ filter: /^@inertiajs\/react$/ }, () => ({ path: 'inertia', namespace: 'fresh-test' }));
    builder.onResolve({ filter: /^sonner$/ }, () => ({ path: 'sonner', namespace: 'fresh-test' }));
    builder.onLoad({ filter: /.*/, namespace: 'fresh-test' }, args => ({ resolveDir: process.cwd(), contents: args.path === 'sonner' ? `export const toast={error(){},success(){}};` : `import React from'react';export const Head=()=>null;export const Link=({children,...props})=>React.createElement('a',props,children);export const usePage=()=>({props:{}});export const router={post(url,payload,options){window.posted=payload;window.renderProps(window.freshPage.props.assessment);options.onSuccess(window.freshPage);options.onFinish();}};` }));
} }] });

async function mount(assessment) {
    const dom = new JSDOM('<div id="root"></div>', { url: 'https://vif.test', runScripts: 'outside-only' });
    dom.window.MessageChannel = class { port1 = { onmessage: null }; port2 = { postMessage: () => setImmediate(() => this.port1.onmessage?.()) }; };
    dom.window.eval(bundle.outputFiles[0].text); await dom.window.renderClient(assessment); return dom;
}
function input(window, label) {
    const element = [...window.document.querySelectorAll('label')].find(el => el.textContent === label);
    assert.ok(element, 'Expected '+label); return window.document.getElementById(element.htmlFor) || element.parentElement.querySelector('input,select,textarea');
}
async function fill(window, element, value) { await window.act(() => { Object.getOwnPropertyDescriptor(Object.getPrototypeOf(element), 'value').set.call(element, value); element.dispatchEvent(new window.Event(element.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true })); }); }
async function save(window) { const button = [...window.document.querySelectorAll('button')].find(el => el.textContent.trim() === 'Save changes'); assert.ok(button); await window.act(() => button.click()); }
async function close(dom) { await dom.window.unmount(); dom.window.close(); }
const assessment = (type, values, readOnly = []) => ({ type, values, read_only_fields: readOnly, save_url: '/synthetic-client', stats: { pct: 0, filled: 0, total: 1 } });

test('actual client page adopts the canonical save response and does not resurrect inactive Work answers', async () => {
    const dom = await mount(assessment('work', { vif_answers: { work_visa_category: 'Accredited Employer Work Visa', employer_nzbn: 'OLD-INACTIVE-NZBN' } }));
    try {
        const w = dom.window;
        assert.equal(input(w, 'Employer New Zealand Business Number (NZBN)').value, 'OLD-INACTIVE-NZBN');
        await fill(w, input(w, 'Which work visa is this information for?'), 'Post-Study Work Visa');
        w.freshPage = { props: { assessment: assessment('work', { vif_answers: { work_visa_category: 'Post-Study Work Visa' } }) } };
        await save(w);
        await fill(w, input(w, 'Which work visa is this information for?'), 'Accredited Employer Work Visa');
        assert.equal(input(w, 'Employer New Zealand Business Number (NZBN)').value, '');
    } finally { await close(dom); }
});

test('actual client page combines refreshed readonly props with fresh staff English evidence values', async () => {
    const dom = await mount(assessment('resident', { english_evidence: 'IELTS', english_test_score: '7', english_test_date: '2026-10-01', job_title: 'Old synthetic job', vif_answers: {} }));
    try {
        const w = dom.window;
        await fill(w, input(w, 'Job Title'), 'Updated synthetic job');
        w.freshPage = { props: { assessment: assessment('resident', { english_evidence: 'Not Required', english_test_score: null, english_test_date: null, job_title: 'Updated synthetic job', vif_answers: {} }, ['english_evidence', 'english_test_score', 'english_test_date']) } };
        await save(w);
        assert.match(w.document.body.textContent, /Not Required · Recorded by staff/);
        assert.equal([...w.document.querySelectorAll('input')].some(el => el.value === 'IELTS' || el.value === '7'), false);
        assert.equal(input(w, 'Job Title').value, 'Updated synthetic job');
    } finally { await close(dom); }
});
