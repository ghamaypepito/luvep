import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';

// Render the actual upload component, without unrelated page/layout imports.
const page = await readFile('resources/js/pages/track/EngagementPack.jsx', 'utf8');
const start = page.indexOf('export function ProofUpload(');
const component = page.slice(start, page.indexOf('// Download every', start));
const bundle = await build({stdin:{contents:`
    import React,{useState,useRef} from 'react';
    import {createRoot} from 'react-dom/client';
    import {flushSync} from 'react-dom';
    const ACCENT='#0f766e',Upload=()=>null,Loader2=()=>null;
    const router={post:(url,data,options)=>window.calls.push({url,data,options})};
    ${component}
    window.calls=[]; window.flushReact=flushSync;
    flushSync(()=>createRoot(document.getElementById('root')).render(<ProofUpload uploadUrl="/engagement/fixture/proof-of-payment" invoiceId={17}/>));
`,resolveDir:process.cwd(),loader:'jsx'},bundle:true,write:false,format:'iife',jsx:'automatic'});

function setup() {
    const dom = new JSDOM('<div id="root"></div>', {url:'http://preview.invalid',runScripts:'dangerously',pretendToBeVisual:true});
    dom.window.eval(bundle.outputFiles[0].text);
    return dom;
}
async function choose(window, files) {
    const input=window.document.querySelector('input[type=file]');
    Object.defineProperty(input,'files',{value:files,configurable:true});
    input.dispatchEvent(new window.Event('change',{bubbles:true}));
    await new Promise(resolve=>setTimeout(resolve,0));
}

test('all selected files and the exact invoice ID are submitted together',async()=>{
    const dom=setup(),{window}=dom;
    try {
        assert.equal(window.document.querySelector('input').multiple,true);
        await choose(window,[new window.File(['a'],'first.pdf'),new window.File(['b'],'second.png'),new window.File(['c'],'third.pdf')]);
        assert.equal(window.calls.length,1);
        assert.equal(window.calls[0].url,'/engagement/fixture/proof-of-payment');
        assert.equal(window.calls[0].data.invoice_id,17);
        assert.deepEqual(Array.from(window.calls[0].data.files,file=>file.name),['first.pdf','second.png','third.pdf']);
        assert.equal(window.calls[0].options.forceFormData,true);
        assert.equal(window.document.querySelector('button').disabled,true);
    } finally {window.close();}
});

test('more than ten files are rejected before any upload',async()=>{
    const dom=setup(),{window}=dom;
    try {
        await choose(window,Array.from({length:11},(_,index)=>new window.File(['x'],`${index}.pdf`)));
        assert.equal(window.calls.length,0);
        assert.match(window.document.querySelector('[role=alert]').textContent,/up to 10 files/);
        assert.equal(window.document.querySelector('button').disabled,false);
    } finally {window.close();}
});

test('server validation errors remain visible and allow another attempt',async()=>{
    const dom=setup(),{window}=dom;
    try {
        await choose(window,[new window.File(['x'],'invalid.pdf')]);
        window.flushReact(()=>{ window.calls[0].options.onError({'files.0':'Choose a valid PDF or image.'});
        window.calls[0].options.onFinish(); });
        await new Promise(resolve=>setTimeout(resolve,0));
        assert.match(window.document.querySelector('[role=alert]').textContent,/valid PDF/);
        assert.equal(window.document.querySelector('button').disabled,false);
    } finally {window.close();}
});
