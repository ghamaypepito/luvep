const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path'),Module=require('node:module');
const {buildSync}=require('esbuild'),{JSDOM}=require('jsdom');const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost/admin/ep-agent#connectors'});
for(const key of ['window','document','HTMLElement','HTMLDialogElement','Event','MouseEvent','MutationObserver'])global[key]=dom.window[key];Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});global.IS_REACT_ACT_ENVIRONMENT=true;
HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};HTMLDialogElement.prototype.close=function(){this.removeAttribute('open');};
const React=require('react'),{act}=React,{createRoot}=require('react-dom/client');
const result=buildSync({entryPoints:[path.resolve('resources/js/pages/admin/EpAgentOverview.jsx')],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:['react','react-dom','lucide-react'],alias:{'@inertiajs/react':path.resolve('tests/Frontend/helpers/ep-inertia.cjs')},loader:{'.css':'empty'}});const m=new Module(path.resolve('tests/Frontend/ep-admin-bundle.cjs'));m.filename=m.id;m.paths=Module._nodeModulePaths(process.cwd());m._compile(result.outputFiles[0].text,m.filename);const View=m.exports.AdminView;
const summary={period:'month',period_start:'2026-11-01T00:00:00Z',as_of:'2026-11-06T12:00:00Z',paid_enabled:false,runs:{total:1,states:{blocked:1}},ledger:{actual_micros:'0',open_hold_micros:'500000',reconciled_count:0,unresolved_count:1,unknown_cost_count:0,unattributed_count:1,input_tokens:0,output_tokens:0,cached_read_tokens:0,cached_write_tokens:0,tool_units:0},budgets:{data:[],total:0,has_more:false},connector:{can_manage:true,ep_transport_connected:false,legacy_configured:true,legacy_staff_model:'test-model',legacy_staff_enabled:true,settings_url:'/admin/ai-connectors'}};
const row={id:7,actor:{id:2,name:'Emma Hall'},portal:'education',state:'blocked',reason_label:'Provider not activated',created_at:'2026-11-06T12:00:00Z',updated_at:'2026-11-06T12:00:00Z',actual_micros:null,upper_bound_micros:null,input_tokens:null,output_tokens:null,cached_read_tokens:null,cached_write_tokens:null,tool_units:null,provider:null,model:null,pricing_version:null};
const response=(data,status=200)=>({ok:status<400,status,json:async()=>data});const tick=()=>new Promise(r=>setTimeout(r,0));let root;
async function mount(){root=createRoot(document.getElementById('root'));await act(async()=>{root.render(React.createElement(View));await tick();});}
async function click(el){assert.ok(el,'Missing control');el.focus();await act(async()=>{el.dispatchEvent(new MouseEvent('click',{bubbles:true}));await tick();});}
const button=text=>[...document.querySelectorAll('button')].find(el=>el.textContent===text);
const fake=()=>{global.fetch=async(url)=>url.includes('/overview')?response(summary):url.includes('/runs/7')?response({run:row}):response({data:[row],next_before:null});};
test.afterEach(async()=>{if(root)await act(async()=>root.unmount());root=null;document.getElementById('root').innerHTML='';dom.reconfigure({url:'http://localhost/admin/ep-agent#connectors'});});
test('connector deep link shows existing settings without an active Ep connection or credential form',async()=>{
 fake();await mount();assert.match(document.body.textContent,/Ep generation is off/i);assert.ok(document.querySelector('a[href="/admin/ai-connectors"]'));assert.equal(document.querySelector('input[type=password]'),null);assert.doesNotMatch(document.body.textContent,/SECRET/);
});
test('tasks and detail show unknown cost honestly and refresh closes details',async()=>{
 fake();await mount();await click(button('Tasks'));assert.match(document.body.textContent,/Not recorded/);const open=document.querySelector('button[aria-label="View run 7"]');await click(open);assert.ok(document.querySelector('dialog[open]'));assert.match(document.querySelector('dialog').textContent,/Emma Hall/);assert.doesNotMatch(document.querySelector('dialog').textContent,/conversation_id|idempotency/);
 await click(document.querySelector('[aria-label="Close run details"]'));assert.equal(document.activeElement,open);await click(open);
 Object.defineProperty(document,'hidden',{value:false,configurable:true});await act(async()=>{document.dispatchEvent(new Event('visibilitychange'));await tick();});assert.equal(document.querySelector('dialog[open]'),null);
});
test('latest filter wins and access denial clears names, costs and detail state',async()=>{
 fake();await mount();await click(button('Tasks'));let resolveOld;
 global.fetch=async(url)=>url.includes('/overview')?response(summary):url.includes('portal=education')?new Promise(r=>resolveOld=r):response({data:[{...row,actor:{id:3,name:'Latest owner'},portal:'immigration'}],next_before:null});
 const select=document.querySelector('select[aria-label="Task portal"]');await act(async()=>{select.value='education';select.dispatchEvent(new Event('change',{bubbles:true}));await tick();});await act(async()=>{select.value='immigration';select.dispatchEvent(new Event('change',{bubbles:true}));await tick();});
 await act(async()=>{resolveOld(response({data:[row],next_before:null}));await tick();});assert.match(document.body.textContent,/Latest owner/);assert.doesNotMatch(document.body.textContent,/Emma Hall/);
 global.fetch=async()=>response({},403);await click(document.querySelector('[aria-label="Refresh Ep Admin"]'));assert.doesNotMatch(document.body.textContent,/Latest owner/);assert.match(document.body.textContent,/access|session/i);
});
test('usage shows individual budgets and unresolved holds without inventing a shared limit',async()=>{
 fake();await mount();await click(button('Usage & budgets'));assert.match(document.body.textContent,/No budget configured/);assert.match(document.body.textContent,/US\$0.50/);assert.doesNotMatch(document.body.textContent,/\$30/);
});
test('incomplete usage is visible without discarding its known recorded portion',async()=>{
 const incomplete={...summary,ledger:{...summary.ledger,input_tokens:null,unknown_usage_count:1,meter_coverage:{input_tokens:{recorded:10,missing_entries:1}}}};
 global.fetch=async(url)=>url.includes('/overview')?response(incomplete):response({data:[],next_before:null});await mount();await click(button('Usage & budgets'));
 assert.match(document.body.textContent,/10 recorded.*1 missing/);assert.match(document.body.textContent,/missing usage/i);
});
