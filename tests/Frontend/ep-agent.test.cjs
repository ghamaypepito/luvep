const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { buildSync } = require('esbuild');
const { JSDOM } = require('jsdom');
const dom = new JSDOM('<div id="root"></div>', { url:'http://localhost/ep-agent' });
for(const key of ['window','document','HTMLElement','Event','MouseEvent','KeyboardEvent','MutationObserver']) global[key]=dom.window[key];
Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});
global.IS_REACT_ACT_ENVIRONMENT=true;
const React=require('react');const {act}=React;const {createRoot}=require('react-dom/client');
function load(file){
 const result=buildSync({entryPoints:[path.resolve(file)],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:['react','react-dom','lucide-react'],alias:{'@':path.resolve('resources/js'),'@inertiajs/react':path.resolve('tests/Frontend/helpers/ep-inertia.cjs')},loader:{'.css':'empty'}});
 const module=new Module(path.resolve('tests/Frontend/ep-agent-bundle.cjs'));module.filename=module.id;module.paths=Module._nodeModulePaths(process.cwd());module._compile(result.outputFiles[0].text,module.filename);return module.exports;
}
const Panel=load('resources/js/components/ep-agent/ConversationPanel.jsx').default;
const tick=()=>new Promise(resolve=>setTimeout(resolve,0));
const reply=(data,status=200)=>({ok:status<400,status,json:async()=>data});
let root;
const byText=(selector,text)=>[...document.querySelectorAll(selector)].find(el=>el.textContent.includes(text));
async function mount(props={}){root=createRoot(document.getElementById('root'));await act(async()=>{root.render(React.createElement(Panel,{portal:'education',portals:['education'],...props}));await tick();});}
async function click(el){assert.ok(el,'Missing button');await act(async()=>{el.dispatchEvent(new MouseEvent('click',{bubbles:true}));await tick();});}
async function input(text){const el=document.querySelector('textarea');await act(async()=>{Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype,'value').set.call(el,text);el.dispatchEvent(new Event('input',{bubbles:true}));await tick();});}
test.afterEach(async()=>{if(root){await act(async()=>root.unmount());root=null;}document.getElementById('root').innerHTML='';});
test('failed saves preserve draft and do not invent an assistant reply',async()=>{
 global.fetch=async(url,options)=>url.endsWith('/conversations')&&options.method==='GET'?reply({data:[]}):url.endsWith('/conversations')?reply({id:7},201):reply({},503);
 await mount();await input('Draft my email');await click(byText('button','Save request'));
 assert.equal(document.querySelector('textarea').value,'Draft my email');assert.match(document.body.textContent,/not saved|could not save/i);assert.doesNotMatch(document.body.textContent,/Here is your draft/);
});
test('saved requests show paid-off status and retain the persisted conversation id',async()=>{
 let changed;global.fetch=async(url,options)=>{
  if(url.endsWith('/conversations')&&options.method==='GET')return reply({data:[]});
  if(url.endsWith('/conversations'))return reply({id:7,title:'My task',portal:'education'},201);
  if(url.endsWith('/messages'))return reply({id:11,role:'user',content:'Prepare a presentation'},201);
  if(url.endsWith('/runs'))return reply({id:3,state:'blocked',reason:'provider_not_activated'},201);
  return reply({});
 };
 await mount({onConversationChange:id=>changed=id});await input('Prepare a presentation');await click(byText('button','Save request'));
 assert.equal(changed,7);assert.equal(document.querySelector('textarea').value,'');assert.match(document.body.textContent,/generation.*off|generation.*not enabled/i);assert.match(document.body.textContent,/Prepare a presentation/);
});
test('late conversation results cannot replace the latest selection and revocation clears content',async()=>{
 let resolveFirst;global.fetch=async(url)=>{
  if(url.endsWith('/conversations'))return reply({data:[{id:1,title:'First',portal:'education'},{id:2,title:'Second',portal:'education'}]});
  if(url.endsWith('/1'))return new Promise(resolve=>resolveFirst=resolve);
  if(url.endsWith('/2'))return reply({conversation:{id:2,title:'Second',portal:'education'},messages:[{id:22,role:'user',content:'Latest thread'}]});
  return reply({},403);
 };
 await mount();await click(byText('button','First'));await click(byText('button','Second'));
 await act(async()=>{resolveFirst(reply({conversation:{id:1,title:'First',portal:'education'},messages:[{id:1,role:'user',content:'STALE PRIVATE'}]}));await tick();});
 assert.match(document.body.textContent,/Latest thread/);assert.doesNotMatch(document.body.textContent,/STALE PRIVATE/);
 global.fetch=async()=>reply({},403);
 await click(document.querySelector('button[aria-label="Refresh conversation"]'));
 assert.doesNotMatch(document.body.textContent,/Latest thread/);assert.match(document.body.textContent,/access|unavailable/i);
});
test('task-status outage after saving never reports a saved message as unsaved',async()=>{
 global.fetch=async(url,options)=>{
  if(url.endsWith('/conversations')&&options.method==='GET')return reply({data:[]});
  if(url.endsWith('/conversations'))return reply({id:7,portal:'education'},201);
  if(url.endsWith('/messages'))return reply({id:12,role:'user',content:'Saved once'},201);
  throw Error('network lost');
 };
 await mount();await input('Saved once');await click(byText('button','Save request'));
 assert.equal(document.querySelector('textarea').value,'');assert.match(document.body.textContent,/request is saved|request saved/i);assert.doesNotMatch(document.body.textContent,/Request not saved/);
});
test('switching from an archived conversation to an active one restores the composer',async()=>{
 global.fetch=async(url)=>url.endsWith('/conversations')?reply({data:[{id:2,title:'Active thread',portal:'education'}]}):url.endsWith('/1')?reply({conversation:{id:1,title:'Archived',portal:'education',archived_at:'2026-11-06'},messages:[]}):reply({conversation:{id:2,title:'Active thread',portal:'education',archived_at:null},messages:[]});
 await mount({initialConversationId:1});assert.equal(document.querySelector('textarea').disabled,true);
 await click(byText('button','Active thread'));assert.equal(document.querySelector('textarea').disabled,false);
});
test('new chat restores matching initial record and portal after another portal thread',async()=>{
 let sent;global.fetch=async(url,options)=>{
  if(url.endsWith('/conversations')&&options.method==='GET')return reply({data:[{id:2,title:'Immigration thread',portal:'immigration'}]});
  if(url.endsWith('/2'))return reply({conversation:{id:2,title:'Immigration thread',portal:'immigration'},messages:[]});
  if(url.endsWith('/conversations')){sent=JSON.parse(options.body);return reply({id:8,portal:sent.portal},201);}
  if(url.endsWith('/messages'))return reply({id:9,role:'user',content:'New scoped request'},201);
  return reply({id:1,state:'blocked'},201);
 };
 await mount({portals:['education','immigration'],context:{id:5,label:'Education client',portal:'education'}});
 await click(byText('button','Immigration thread'));await click(byText('button','New conversation'));await input('New scoped request');await click(byText('button','Save request'));
 assert.equal(sent.portal,'education');assert.equal(sent.lead_id,5);
});
test('unsaved drafts warn on browser and Inertia navigation',async()=>{
 global.fetch=async()=>reply({data:[]});await mount();await input('Unsaved work');
 const event=new Event('beforeunload',{cancelable:true});window.dispatchEvent(event);assert.equal(event.defaultPrevented,true);
 let prevented=false;window.confirm=()=>false;
 for(const hook of global.__epBeforeHooks||[])hook({preventDefault:()=>prevented=true});assert.equal(prevented,true);
});
