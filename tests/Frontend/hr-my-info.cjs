const assert=require('node:assert/strict'),path=require('node:path'),Module=require('node:module');
const {buildSync}=require('esbuild'),{JSDOM}=require('jsdom');
const dom=new JSDOM('<div id="root"></div>',{url:'https://luvep.test/portal/hr/documents?scope=mine'});
for(const k of ['window','document','HTMLElement','Event','MouseEvent'])global[k]=dom.window[k];
Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});global.IS_REACT_ACT_ENVIRONMENT=true;
const React=require('react'),{act}=React,{createRoot}=require('react-dom/client');
let authRole='sales';let hr={employee:{id:7},preview:false,permissions:{}},url='/portal/hr/documents?scope=mine';const requests=[];
const inertia={Head:()=>null,Link:({children,...props})=>React.createElement('a',props,children),usePage:()=>({url,props:{hr,auth:{user:{role:authRole}}}}),router:{post(){},reload(){}},useForm:initial=>{
 const [data,set]=React.useState(initial);return{data,errors:{},processing:false,setData:(key,value)=>set(d=>typeof key==='object'?key:{...d,[key]:value}),reset(){},post:(target,options)=>requests.push({target,data,options})};
}};
function load(file){const source=buildSync({entryPoints:[file],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',alias:{'@':path.resolve('resources/js')},external:['react','react-dom','@inertiajs/react']}).outputFiles[0].text;const mod=new Module(path.resolve('hr-my-info-test.cjs'),module);mod.filename=path.resolve('hr-my-info-test.cjs');mod.paths=module.paths;mod.require=name=>name==='@inertiajs/react'?inertia:module.require(name);mod._compile(source,mod.filename);return mod.exports.default;}
const Documents=load('resources/js/pages/hr/Documents.jsx'),Policies=load('resources/js/pages/hr/Policies.jsx'),Equipment=load('resources/js/pages/hr/Equipment.jsx'),root=createRoot(document.getElementById('root'));
const render=(Component,props)=>act(async()=>root.render(React.createElement(Component,props)));
(async()=>{
 await render(Documents,{documents:[],employees:[],classifications:[],scope:'mine',selfUpload:true,selfEmployeeId:7});
 assert.equal(document.querySelector('[aria-label="My Info"] a[href="/portal/hr/equipment?scope=mine"]').textContent,'My equipment');
 assert.ok(document.querySelector('[aria-label="My Info"] a[href="/portal/hr/documents?scope=mine"][aria-current="page"]'));
 assert.match(document.body.textContent,/Upload my document/);assert.equal(document.querySelector('select'),null,'Owner upload cannot select another employee');
 assert.match(document.querySelector('input[type=file]').accept,/\.docx/);
 await act(async()=>document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
 assert.equal(requests.at(-1).target,'/portal/hr/documents');assert.equal(requests.at(-1).data.employee_id,7);assert.equal(requests.at(-1).data.classification,'employee_shared');
 await render(Documents,{documents:[{id:1,title:'Staff file',classification:'employee_shared',submitted_by:7,can_manage:true,archived:false,versions:[{id:5,version:1,original_filename:'copy.pdf',owner_uploaded:true,size:100,created_at:'2026-11-18T00:00:00Z'}]}],employees:[],classifications:['employee_shared'],scope:'manage'});
 assert.match(document.body.textContent,/available in My Info/);assert.equal(document.body.textContent.includes('Release to employee'),false,'Owner-uploaded files already belong to the employee');
 hr={...hr,preview:true};await render(Documents,{documents:[],employees:[],classifications:[],scope:'mine',selfUpload:true,selfEmployeeId:7});assert.equal(document.querySelector('input[type=file]'),null,'Read-only preview has no upload control');
 hr={...hr,preview:false};url='/portal/hr/policies?scope=mine';
 await render(Policies,{policies:[{id:1,revision:1,versions:[{id:12,version:1,title:'Leave policy and guide',body:'Published text',attachment:{name:'leave-guide.pdf',url:'/portal/hr/policies/versions/12/attachment'}}]}],manage:false,scope:'mine'});
 assert.match(document.body.textContent,/Upload a policy document to my record/);assert.match(document.body.textContent,/does not publish/);assert.equal(document.querySelector('a[href="/portal/hr/policies/versions/12/attachment"]').textContent,'Download leave-guide.pdf');assert.equal(document.body.textContent.includes('Publish new version'),false);
 hr={...hr,employee:null};authRole='admin';await render(Documents,{documents:[],employees:[],classifications:['employee_shared'],scope:'mine',selfUpload:false});
 assert.ok(document.querySelector('[aria-label="My Info"] a[href="/portal/hr/documents"]'),'Unenrolled Admin can find employee uploads');
 assert.ok(document.querySelector('[aria-label="My Info"] a[href="/portal/hr/equipment"]'),'Unenrolled Admin can find equipment management');
 hr={...hr,employee:{id:7}};authRole='sales';
 await render(Equipment,{items:[],history:[],employees:[],scope:'mine',canAddOwn:true});assert.match(document.body.textContent,/Add my equipment/);assert.equal(document.querySelector('select'),null);
 await act(async()=>document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true})));
 assert.equal(requests.at(-1).target,'/portal/hr/equipment/self');assert.equal(requests.at(-1).data.employee_id,undefined);assert.equal(requests.at(-1).data.asset_tag,undefined,'Staff cannot claim an HR asset tag');
 await act(async()=>root.unmount());dom.window.close();console.log('HR My Info UI: owner-only tabs/uploads, Word file picker, preview protection, exact policy attachment links, and staff-equipment route passed.');
})().catch(error=>{console.error(error);dom.window.close();process.exitCode=1;});
