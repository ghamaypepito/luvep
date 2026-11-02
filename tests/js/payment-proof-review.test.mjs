import {test} from 'node:test';
import assert from 'node:assert/strict';
import {build} from 'esbuild';
import {JSDOM} from 'jsdom';

const bundle=await build({stdin:{contents:`
import React,{act} from 'react'; import {createRoot} from 'react-dom/client';
import {router} from '@inertiajs/react'; import axios from 'axios';
import ProofReviewModal from './resources/js/components/payments/ProofReviewModal.jsx';
window.IS_REACT_ACT_ENVIRONMENT=true;window.act=act;window.calls=[];
axios.get=async()=>({data:{can_review:true,today:'2026-11-02',status:'Submitted',invoices:[{id:17,number:'INV-QA',currency_recorded:!window.legacyCurrency,summary:{currency:'NZD',total:1200,remaining:1200}}],payments:[{id:9,invoice_id:17,currency:'NZD',amount:400,reference:'BANK-400',paid_at:'2026-11-02'}]}});
router.post=(url,data,options)=>window.calls.push({url,data,options});
window.render=()=>act(async()=>createRoot(document.getElementById('root')).render(<ProofReviewModal proof={{id:2,original_name:'proof.pdf'}} leadId={42} onClose={()=>{}}/>));
`,resolveDir:process.cwd(),loader:'jsx'},bundle:true,write:false,format:'iife',jsx:'automatic'});
async function mount(legacyCurrency=false){
 const dom=new JSDOM('<div id="root"></div>',{url:'http://qa.invalid',runScripts:'outside-only'}),w=dom.window;
 w.MessageChannel=class {port1={onmessage:null};port2={postMessage:()=>setImmediate(()=>this.port1.onmessage?.())};};
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 w.legacyCurrency=legacyCurrency;w.eval(bundle.outputFiles[0].text);await w.render();return dom;
}
async function fill(w,label,value){const target=[...w.document.querySelectorAll('label')].find(e=>e.textContent===label);const e=w.document.getElementById(target.htmlFor);await w.act(async()=>{Object.getOwnPropertyDescriptor(Object.getPrototypeOf(e),'value').set.call(e,value);e.dispatchEvent(new w.Event(e.tagName==='SELECT'?'change':'input',{bubbles:true}));});}
async function click(w,text){await w.act(async()=>[...w.document.querySelectorAll('button')].find(e=>e.textContent===text).click());}
test('actual Inertia form submits a separate receipt without chaining transform',async()=>{const dom=await mount(),w=dom.window;try{
 await fill(w,'Verified payment amount (NZD)','400');await fill(w,'Transfer reference','BANK-400');await click(w,'Confirm payment');
 assert.equal(w.calls.length,1);assert.equal(w.calls[0].url,'/admin/leads/42/documents/2/status');assert.equal(w.calls[0].data.amount,'400');assert.equal(w.calls[0].data.invoice_id,'17');assert.equal(w.calls[0].data.note,null);
}finally{w.close();}});
test('supporting proof submits existing receipt without another amount',async()=>{const dom=await mount(),w=dom.window;try{
 await fill(w,'Payment record','existing');await fill(w,'Existing confirmed payment','9');await click(w,'Confirm payment');
 assert.equal(w.calls.length,1);assert.equal(w.calls[0].data.finance_payment_id,'9');assert.equal(w.calls[0].data.amount,null);
}finally{w.close();}});
test('needs attention submits only the selected proof and reason',async()=>{const dom=await mount(),w=dom.window;try{
 await click(w,'Needs attention');const label=[...w.document.querySelectorAll('label')].find(e=>e.htmlFor.endsWith('-note'));await fill(w,label.textContent,'Please upload a clearer copy');await click(w,'Save needs attention');
 assert.equal(w.calls.length,1);assert.equal(w.calls[0].data.status,'Rejected');assert.equal(w.calls[0].data.note,'Please upload a clearer copy');
}finally{w.close();}});

test('legacy invoice requires explicit currency and submits the selected currency',async()=>{const dom=await mount(true),w=dom.window;try{
 await fill(w,'Verified payment amount (NZD)','400');await fill(w,'Transfer reference','LEGACY-USD');await click(w,'Confirm payment');assert.equal(w.calls.length,0);
 await fill(w,'Invoice currency','USD');await click(w,'Confirm payment');assert.equal(w.calls.length,1);assert.equal(w.calls[0].data.invoice_currency,'USD');
}finally{w.close();}});
