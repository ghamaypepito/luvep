const test=require('node:test'),assert=require('node:assert/strict'),path=require('node:path'),Module=require('node:module');
const {buildSync}=require('esbuild'),{JSDOM}=require('jsdom');const dom=new JSDOM('<div id="root"></div>',{url:'http://localhost/admin'});
for(const key of ['window','document','HTMLElement','Event','CustomEvent','MouseEvent','MutationObserver'])global[key]=dom.window[key];
Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});window.matchMedia=()=>({matches:false,addEventListener(){},removeEventListener(){}});global.IS_REACT_ACT_ENVIRONMENT=true;
const React=require('react'),{act}=React,{createRoot}=require('react-dom/client');
const result=buildSync({entryPoints:[path.resolve('resources/js/components/ai/AssistantDock.jsx')],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:['react','react-dom','lucide-react'],alias:{'@':path.resolve('resources/js'),'@inertiajs/react':path.resolve('tests/Frontend/helpers/ep-inertia.cjs')},loader:{'.css':'empty'}});
const m=new Module(path.resolve('tests/Frontend/ep-navigation-bundle.cjs'));m.filename=m.id;m.paths=Module._nodeModulePaths(process.cwd());m._compile(result.outputFiles[0].text,m.filename);const Dock=m.exports.default;
let root;const tick=()=>new Promise(r=>setTimeout(r,0));async function mount(pilot,ai=true,url='/admin'){global.__epTestPage={url,props:{auth:{user:{id:4,role:'education'},ai_enabled:ai,ep_agent:{enabled:pilot,portals:['education'],manager:false}}}};root=createRoot(document.getElementById('root'));await act(async()=>{root.render(React.createElement(Dock,{initialPage:global.__epTestPage}));await tick();await tick();});}
test.afterEach(async()=>{if(root)await act(async()=>root.unmount());root=null;document.getElementById('root').innerHTML='';});
test('non-pilot staff retain the original assistant',async()=>{await mount(false);assert.match(document.body.textContent,/EP Assistant/);assert.doesNotMatch(document.body.textContent,/Ep Agent/);});
test('pilot chat is available with paid/global AI off and expands only the persisted thread',async()=>{
 global.fetch=async()=>({ok:true,status:200,json:async()=>({data:[]})});await mount(true,false);assert.match(document.body.textContent,/Ep Agent/);assert.doesNotMatch(document.body.textContent,/EP Assistant/);
 await act(async()=>{window.dispatchEvent(new CustomEvent('ep-assistant-open'));await tick();await tick();});assert.ok(!document.querySelector('#ep-agent-panel').hidden);assert.ok(document.querySelector('a[aria-label="Expand Ep Agent"]').getAttribute('href').startsWith('/ep-agent?portal=education'));
});
test('full workspace does not create a duplicate floating chat',async()=>{await mount(true,true,'/ep-agent');assert.equal(document.querySelector('.ep-dock-trigger'),null);});
function extra(file){const r=buildSync({entryPoints:[path.resolve(file)],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:['react','react-dom','lucide-react'],alias:{'@inertiajs/react':path.resolve('tests/Frontend/helpers/ep-inertia.cjs')},loader:{'.css':'empty'}});const mod=new Module(path.resolve('tests/Frontend/ep-extra-bundle.cjs'));mod.filename=mod.id;mod.paths=Module._nodeModulePaths(process.cwd());mod._compile(r.outputFiles[0].text,mod.filename);return mod.exports.default;}
const CTA=extra('resources/js/components/ui/MobileStickyCTA.jsx'),Contact=extra('resources/js/components/ui/FloatingContact.jsx');
test('new internal screens hide public contact overlays while public pages retain them',async()=>{
 for(const url of ['/ep-agent','/ep-manager','/booking']){
  global.__epTestPage={url,props:{contact:{phone:'+64000000',whatsapp:'6400000'}}};root=createRoot(document.getElementById('root'));
  await act(async()=>{root.render(React.createElement(React.Fragment,null,React.createElement(CTA),React.createElement(Contact)));await tick();});
  assert.equal(document.body.textContent.includes('Book Free'),url==='/booking');
  await act(async()=>root.unmount());root=null;
 }
});
test('Manager navigation is not covered by a floating chat trigger',async()=>{await mount(true,true,'/ep-manager');assert.equal(document.querySelector('.ep-dock-trigger'),null);});
