const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path'),Module=require('node:module');
const {buildSync}=require('esbuild'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost/ep-manager'});
for(const key of ['window','document','HTMLElement','HTMLDialogElement','Event','MouseEvent','KeyboardEvent','MutationObserver'])global[key]=dom.window[key];
Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});global.IS_REACT_ACT_ENVIRONMENT=true;
HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};HTMLDialogElement.prototype.close=function(){this.removeAttribute('open');this.dispatchEvent(new Event('close'));};
const React=require('react'),{act}=React,{createRoot}=require('react-dom/client');
const result=buildSync({entryPoints:[path.resolve('resources/js/pages/ai/EpManager.jsx')],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:['react','react-dom','lucide-react'],alias:{'@inertiajs/react':path.resolve('tests/Frontend/helpers/ep-inertia.cjs')},loader:{'.css':'empty'}});
const m=new Module(path.resolve('tests/Frontend/ep-manager-bundle.cjs'));m.filename=m.id;m.paths=Module._nodeModulePaths(process.cwd());m._compile(result.outputFiles[0].text,m.filename);const Manager=m.exports.ManagerView;
let root;const tick=()=>new Promise(r=>setTimeout(r,0)),response=(data,status=200)=>({ok:status<400,status,json:async()=>data});
const base={portal:'education',as_of:'2026-11-06T09:00:00Z',counts:{total:1,inactive:1,unassigned:0,unknown_update:0},records:[{id:9,client:{id:9,name:'Maria Torres'},personnel:{id:4,name:'Emma Hall',role:'education'},stage:'Documents',updated_at:null,portal:'education'}],team:[{id:4,name:'Emma Hall',role:'education',workload:1}],next_before:null,attention_days:7};
const find=(selector,text)=>[...document.querySelectorAll(selector)].find(e=>e.textContent.includes(text));
async function mount(){root=createRoot(document.getElementById('root'));await act(async()=>{root.render(React.createElement(Manager,{ep:{portals:['education','immigration']}}));await tick();});}
async function click(el){assert.ok(el,'Missing control');await act(async()=>{el.focus();el.dispatchEvent(new MouseEvent('click',{bubbles:true}));await tick();});}
test.afterEach(async()=>{if(root)await act(async()=>root.unmount());root=null;document.getElementById('root').innerHTML='';});
test('client and personnel buttons open real allowlisted details and dialogs restore focus',async()=>{
 global.fetch=async(url)=>url.includes('/clients/')?response({client:{id:9,name:'Maria Torres',email:'maria@example.test',phone:null,stage:'Documents'}}):url.includes('/personnel/')?response({personnel:{id:4,name:'Emma Hall',work_email:'emma@example.test',role:'education',workload:1}}):response(base);
 await mount();const client=find('button','Maria Torres');await click(client);assert.ok(document.querySelector('dialog[open]'));assert.match(document.querySelector('dialog').textContent,/maria@example.test/);
 await click(document.querySelector('[aria-label="Close quick details"]'));assert.equal(document.activeElement,client);
 await click(find('button','Emma Hall'));assert.match(document.querySelector('dialog').textContent,/emma@example.test/);assert.match(document.querySelector('dialog').textContent,/1/);
});
test('portal requests cancel stale results and access denial clears records',async()=>{
 let resolve;global.fetch=async(url)=>url.includes('portal=education')?new Promise(r=>resolve=r):response({...base,portal:'immigration',records:[{...base.records[0],client:{id:9,name:'Immigration client'}}]});
 await mount();const select=document.querySelector('select');await act(async()=>{select.value='immigration';select.dispatchEvent(new Event('change',{bubbles:true}));await tick();});
 await act(async()=>{resolve(response(base));await tick();});assert.match(document.body.textContent,/Immigration client/);assert.doesNotMatch(document.body.textContent,/Maria Torres/);
 global.fetch=async()=>response({},403);await click(document.querySelector('[aria-label="Refresh Manager"]'));assert.doesNotMatch(document.body.textContent,/Immigration client/);assert.match(document.body.textContent,/access|session/i);
});
test('attention uses server filter and unknown timestamps stay unknown',async()=>{
 const urls=[];global.fetch=async(url)=>{urls.push(url);return response(base);};await mount();assert.match(document.body.textContent,/Update not recorded/);
 await click(find('button','Attention'));assert.ok(urls.some(url=>url.includes('attention=1')));
 await click(find('button','Ask Ep'));assert.match(document.body.textContent,/generation is off/i);assert.equal(document.querySelector('a[href="/ep-agent?portal=education"]').textContent,'Open private chat');
});
