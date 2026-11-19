const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const { JSDOM } = require('jsdom');
const { buildSync } = require('esbuild');
const dom = new JSDOM('<div id="root"></div>', {url:'https://luvep.test/portal/immigration/cases/engagement'});
for (const key of ['window','document','HTMLElement','HTMLDialogElement','Event','MouseEvent','FormData','File']) global[key]=dom.window[key];
Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});
global.IS_REACT_ACT_ENVIRONMENT=true;
HTMLDialogElement.prototype.showModal=function(){this.setAttribute('open','');};
URL.createObjectURL=()=> 'blob:synthetic-signature';URL.revokeObjectURL=()=>{};
const React=require('react'),{act}=React,{createRoot}=require('react-dom/client');
let requests=[],fail=false,reloads=0;
const axios={post:async(url,data)=>{requests.push({url,data});if(fail)throw {response:{data:{errors:{signature_image:['Synthetic upload failure. Retry.']}}}};return {data:{notifications:{client:data.get('notify_client')==='1'?'queued':'not_requested',internal:data.get('notify_internal')==='1'?'queued':'not_requested'}}};}};
const empty=()=>null;
const inertia={Head:empty,Link:empty,router:{reload:()=>reloads++}};
function load(entry,externals,mocks){const code=buildSync({entryPoints:[entry],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',external:externals}).outputFiles[0].text;const m=new Module(path.resolve(entry+'.test.cjs'),module);m.filename=path.resolve(entry+'.test.cjs');m.paths=module.paths;m.require=name=>mocks(name)??module.require(name);m._compile(code,m.filename);return m.exports.default;}
const Modal=load('resources/js/components/immigration/StaffSignatureUpload.jsx',['react','react-dom','axios'],name=>name==='axios'?axios:null);
const Page=load('resources/js/pages/portal/immigration/Engagement.jsx',['react','react-dom','@/*','@inertiajs/react','sonner','lucide-react'],name=>{
 if(name==='@inertiajs/react')return inertia;
 if(name==='lucide-react')return new Proxy({},{get:()=>empty});
 if(name==='sonner')return {toast:{}};
 if(name.endsWith('/StaffSignatureUpload'))return Modal;
 if(name.startsWith('@/'))return {__esModule:true,default:empty,AvatarPhoto:empty,confirmDialog:empty};
 return null;
});
const root=createRoot(document.getElementById('root'));
const row={case_id:1,case_name:'Synthetic Client',pack_id:'test-pack',is_draft:false,signed:false,documents:[{id:5,type_key:'written_agreement',type_label:'Written Agreement',signed:false,view_url:'/private/5',download_url:'/private/5'}]};
const click=async(node)=>{assert.ok(node);await act(async()=>node.click());};
const button=name=>[...(document.querySelector('dialog') || document).querySelectorAll('button')].find(b=>b.textContent.trim()===name);
const choice=text=>[...document.querySelectorAll('label')].find(l=>l.textContent.trim().startsWith(text))?.querySelector('input');
const render=async(props={})=>act(async()=>root.render(React.createElement(Page,{generated:[row],canUploadSignature:true,internalSignatureNotificationsReady:true,...props})));
const file=async(type='image/png',size=8)=>{const input=document.querySelector('input[type="file"]');Object.defineProperty(input,'files',{value:[new File(['x'.repeat(size)],type==='image/png'?'signature.png':'file.pdf',{type})],configurable:true});await act(async()=>input.dispatchEvent(new Event('change',{bubbles:true})));};
(async()=>{
 await render({canUploadSignature:false});assert.equal(button('Upload signature'),undefined);
 await render({generated:[{...row,is_draft:true}]});assert.equal(button('Upload signature'),undefined);
 await render();await click(button('Upload signature'));assert.ok(document.querySelector('dialog[open]'));
 assert.equal(choice('Do not send an email').checked,true);assert.equal(choice('Do not send an internal').checked,true);
 assert.equal(button('Upload signature').disabled,true,'Authorization and file required');
 await file('application/pdf');assert.match(document.querySelector('[role="alert"]').textContent,/PNG or JPG/);
 await file();await click(choice('I confirm'));
 for (const client of [false,true]) for (const internal of [false,true]) {
   await click(choice(client?'Notify the client':'Do not send an email'));
   await click(choice(internal?'Send an internal email notification':'Do not send an internal'));
   assert.equal(choice('Notify the client').checked,client);assert.equal(choice('Do not send an email').checked,!client);
   assert.equal(choice('Send an internal email notification').checked,internal);assert.equal(choice('Do not send an internal').checked,!internal);
 }
 fail=true;await click(button('Upload signature'));assert.match(document.querySelector('[role="alert"]').textContent,/Retry/);
 assert.equal(choice('I confirm').checked,true);assert.ok(document.querySelector('img[alt="Selected client signature"]'));
 fail=false;await click(button('Upload signature'));assert.equal(reloads,1);assert.match(document.querySelector('[role="status"]').textContent,/Signature saved/);
 assert.equal(requests.at(-1).url,'/portal/immigration/engagement/documents/5/signature');
 assert.equal(requests.at(-1).data.get('authorized'),'1');assert.equal(requests.at(-1).data.get('notify_client'),'1');assert.equal(requests.at(-1).data.get('notify_internal'),'1');
 await click(button('Close'));assert.equal(document.querySelector('dialog'),null);
 await render({internalSignatureNotificationsReady:false});await click(button('Upload signature'));
 assert.equal(choice('Send an internal email notification').disabled,true);assert.match(document.body.textContent,/No active Immigration management email recipients/);
 await click(button('Cancel'));
 await render({generated:[{...row,signed:true,signature_uploaded_by:'Synthetic Uploader'}]});assert.equal(button('Upload signature'),undefined);assert.match(document.body.textContent,/Uploaded by staff: Synthetic Uploader/);
 await act(async()=>root.unmount());console.log('Staff signature actual-page tests passed: authorized action, image validation, independent notification pairs, retry, saved state, pending-team gate and signed/draft isolation');
})().catch(e=>{console.error(e);process.exitCode=1;});
