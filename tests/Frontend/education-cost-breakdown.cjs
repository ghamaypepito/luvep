const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');
const dom = new JSDOM('<div id="root"></div>', { url: 'https://luvep.test/portal/education/leads/proposals-agreements' });
for (const key of ['window', 'document', 'HTMLElement', 'Event', 'MouseEvent', 'Blob']) global[key] = dom.window[key];
global.IS_REACT_ACT_ENVIRONMENT = true;
const React = require('react'), { act } = React, { createRoot } = require('react-dom/client');
const clients = [
    {id:1,name:'Philippine Client',country:'Philippines',category:'philippines',currency:'PHP'},
    {id:2,name:'New Zealand Client',country:'New Zealand',category:'onshore',currency:'NZD'},
    {id:3,name:'Indian Client',country:'India',category:'offshore',currency:'INR'},
    {id:4,name:'Unknown Client',country:'',category:'',currency:''},
];
const requests = []; let failRate = false, failSave = false;
const axios = { get: async (url, options) => {
    if (url.endsWith('/rates')) {
        if(failRate) throw {response:{data:{errors:{rate:['Rate service temporarily unavailable']}}}};
        const {source,target}=options.params; const rates={NZD:{rate:1,date:'2026-11-18'}};
        for(const code of [source,target]) rates[code]={rate:code==='NZD'?1:code==='PHP'?35:50,date:'2026-11-18'};
        return {data:{source_currency:source,target_currency:target,rates,token:`${source}-${target}`,provider:'Frankfurter reference rates'}};
    }
    return {data:{clients,documents:[]}};
}, post:async(url,data)=>{
    requests.push(data);
    if(failSave) throw {response:{data:{errors:{rate:['Refresh the exchange rate before generating this breakdown.']}}}};
    return {data:data.action==='preview'?{html:'<h1>Synthetic preview</h1>'}:{id:1}};
}};
const compiled=buildSync({entryPoints:['resources/js/pages/portal/sales/ProposalsAgreements.jsx'],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',alias:{'@':path.resolve('resources/js')},external:['react','react-dom','@inertiajs/react','axios']}).outputFiles[0].text;
const component=new Module(path.resolve('education-cost-test.cjs'),module);component.filename=path.resolve('education-cost-test.cjs');component.paths=module.paths;
component.require=name=> name==='axios'?axios:name==='@inertiajs/react'?{Head:()=>null,Link:({children,...props})=>React.createElement('a',props,children),router:{}}:module.require(name);
component._compile(compiled,component.filename);
const Page=component.exports.default, root=createRoot(document.getElementById('root'));
const button=name=>[...document.querySelectorAll('button')].find(b=>b.textContent.trim().startsWith(name));
const label=name=>[...document.querySelectorAll('label')].find(l=>l.firstChild?.textContent===name)?.querySelector('input,select');
const click=async(node)=>{assert.ok(node);await act(async()=>node.click());};
const input=async(node,value)=>{assert.ok(node);await act(async()=>{const proto=node.tagName==='SELECT'?window.HTMLSelectElement.prototype:node.tagName==='TEXTAREA'?window.HTMLTextAreaElement.prototype:window.HTMLInputElement.prototype;Object.getOwnPropertyDescriptor(proto,'value').set.call(node,value);node.dispatchEvent(new Event(node.tagName==='SELECT'?'change':'input',{bubbles:true}));});};
const settle=async()=>act(async()=>new Promise(r=>setTimeout(r,320)));
(async()=>{
    await act(async()=>root.render(React.createElement(Page,{portal:'sales'})));
    assert.equal(button('Breakdown Cost'),undefined,'No Sales tab added');
    await act(async()=>root.render(React.createElement(Page,{portal:'education'})));
    await click(button('Breakdown Cost'));await settle();
    await input(label('Client'),'1');await settle();
    assert.equal(label('Country of residence').value,'Philippines');assert.equal(label('Category').value,'philippines');
    assert.equal(label('Original fee currency').value,'PHP');assert.equal(label('Local estimate currency').value,'PHP');
    await input(document.querySelector('[aria-label="Description 1"]'),'School enrolment');
    await input(document.querySelector('[aria-label="Amount 1"]'),'3500');
    assert.match(document.body.textContent,/100.00/);assert.equal(button('Preview breakdown').disabled,false);
    await input(label('Applicants'),'couple_child');
    await click(button('Add cost item'));
    await input(document.querySelector('[aria-label="Description 2"]'),'Insurance');await input(document.querySelector('[aria-label="Amount 2"]'),'1750');
    assert.match(document.body.textContent,/150.00/);
    await click(button('Preview breakdown'));assert.ok(document.querySelector('iframe[title="Cost breakdown preview"]'));
    assert.equal(requests[0].family,'couple_child');assert.equal(requests[0].rows.length,2);
    await input(document.querySelector('[aria-label="Amount 2"]'),'1800');assert.equal(document.querySelector('iframe'),null,'Editing clears stale preview');
    failSave=true;await click(button('Save new breakdown'));assert.match(document.querySelector('[role="alert"]').textContent,/Refresh the exchange rate/);failSave=false;
    await click(button('Save new breakdown'));assert.match(document.body.textContent,/New cost breakdown saved/);
    await input(label('Category'),'offshore');assert.equal(label('Country of residence').value,'Philippines','Staff override does not alter residence');
    await input(label('Client'),'2');await settle();assert.equal(label('Category').value,'onshore');assert.equal(label('Original fee currency').value,'NZD');assert.equal(document.querySelector('[aria-label="Amount 1"]').value,'');
    await input(label('Client'),'3');await settle();assert.equal(label('Local estimate currency').value,'INR');assert.equal(label('Category').value,'offshore');
    failRate=true;await click(button('Refresh rates'));await settle();assert.match(document.body.textContent,/Rate service temporarily unavailable/);assert.equal(button('Download PDF').disabled,true);
    failRate=false;await click(button('Refresh rates'));await settle();assert.match(document.body.textContent,/Frankfurter/);
    await input(label('Client'),'4');await settle();assert.equal(label('Category').value,'');assert.equal(label('Local estimate currency').value,'');assert.equal(button('Save new breakdown').disabled,true);
    await act(async()=>root.unmount());console.log('Education breakdown actual-page interactions passed: tab isolation, residence defaults, overrides, family, editable rows, conversions, preview/save, failures and retry');
})().catch(e=>{console.error(e);process.exitCode=1;});
