const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const React = require('react');
const { act } = React;
const { createRoot } = require('react-dom/client');
const dom = new JSDOM('<div id="root"></div>', {url:'https://luvep.test/consultancy-verification'});
for (const key of ['window','document','HTMLElement','Event','MouseEvent']) global[key] = dom.window[key];
global.IS_REACT_ACT_ENVIRONMENT = true;
let confirmed = false, confirmation, requests = [], errors = [];
const empty = () => null;
const compiled = buildSync({entryPoints:['resources/js/pages/admin/ConsultancyVerification.jsx'],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:['react','@inertiajs/react','sonner','lucide-react','@/components/ui/ConfirmDialog']}).outputFiles[0].text;
const component = new Module(path.resolve('consultancy-draft-delete.test.cjs'),module);
component.filename = path.resolve('consultancy-draft-delete.test.cjs'); component.paths = module.paths;
component.require = name => name === '@inertiajs/react' ? {Head:empty,Link:({children})=>React.createElement('a',null,children),router:{post(){},delete:(...args)=>requests.push(args)}}
    : name === 'sonner' ? {toast:{error:message=>errors.push(message)}} : name === 'lucide-react' ? new Proxy({},{get:()=>empty})
    : name === '@/components/ui/ConfirmDialog' ? {confirmDialog:async options=>{confirmation=options;return confirmed;}} : module.require(name);
component._compile(compiled,component.filename);
const Page = component.exports.default;
const root = createRoot(document.getElementById('root'));
const row = {id:42,name:'Synthetic Client',lead_id:'TEST42',initials:'SC',status:'pending',can_approve:true,delete_token:'a'.repeat(64),scenario:'english_engagement',scenario_label:'English agreement',currency_symbol:'Php',total_amount:14500,items:[{key:'english_review',label:'English Review',amount:14500,currency_symbol:'Php'},{key:'pte_exam',label:'PTE Examination Fee',amount:240,currency_symbol:'US$'}]};
const button = name => [...document.querySelectorAll('button')].find(el=>el.textContent.trim()===name);
const click = async node => {assert.ok(node);await act(async()=>node.click());};
const render = async record => act(async()=>root.render(React.createElement(Page,{proposals:[record],counts:{pending:1}})));
(async()=>{
    await render(row);
    assert.match(document.body.textContent,/14,500/); assert.doesNotMatch(document.body.textContent,/14,740/);
    await click(button('Delete draft')); assert.equal(requests.length,0,'Cancel never deletes');
    assert.match(confirmation.message,/Previously issued documents will be retained/);
    confirmed=true; await click(button('Delete draft'));
    assert.equal(requests.length,1); assert.equal(requests[0][0],'/consultancy-verification/42/draft');
    assert.deepEqual(requests[0][1].data,{delete_token:row.delete_token});
    assert.equal(button('Deleting…').disabled,true); assert.equal(button('Approve').disabled,true);
    await act(async()=>requests[0][1].onError({draft:'Draft changed. Refresh.'})); assert.deepEqual(errors,['Draft changed. Refresh.']);
    await act(async()=>requests[0][1].onFinish()); assert.equal(button('Delete draft').disabled,false);
    await click(button('English agreement'));
    assert.ok([...document.querySelectorAll('span')].some(el=>el.textContent==='US$'),'Exam editor shows its USD currency');
    for(const status of ['verified','approved']) {await render({...row,status,delete_token:null});assert.equal(button('Delete draft'),undefined);}
    await act(async()=>root.unmount());
    console.log('Consultancy draft interactions passed: cancel, guarded delete, busy state, error, amount, separate USD, protected records');
})().catch(error=>{console.error(error);process.exitCode=1;});
