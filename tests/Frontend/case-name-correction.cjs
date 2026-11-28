const assert = require('node:assert/strict');
const path = require('node:path');
const Module = require('node:module');
const {JSDOM} = require('jsdom');
const {buildSync} = require('esbuild');
const dom = new JSDOM('<div id="root"></div>', {url:'https://luvep.test/portal/immigration/cases'});
for (const key of ['window','document','HTMLElement','Event','MouseEvent']) global[key]=dom.window[key];
Object.defineProperty(global,'navigator',{value:dom.window.navigator,configurable:true});
global.IS_REACT_ACT_ENVIRONMENT=true;
const React=require('react'), {act}=React, {createRoot}=require('react-dom/client');
const calls=[];
const inertia={usePage:()=>({props:{auth:{user:{role:'immigration_manager'}}}}),Head:()=>null,Link:({children,...props})=>React.createElement('a',props,children),router:{post:(...args)=>calls.push(args)},
 useForm(initial){const [data,set]=React.useState(initial);return {data,setData:(key,value)=>set(old=>({...old,[key]:value})),errors:{},isDirty:false,processing:false,defaults(){},clearErrors(){},reset(){},post:(...args)=>calls.push(args)};}};
function compile(entry){const code=buildSync({entryPoints:[entry],bundle:true,write:false,format:'cjs',platform:'node',jsx:'automatic',alias:{'@':path.resolve('resources/js')},external:['react','react-dom','@inertiajs/react']}).outputFiles[0].text;const m=new Module(path.resolve(entry+'.test.cjs'),module);m.filename=path.resolve(entry+'.test.cjs');m.paths=module.paths;m.require=name=>name==='@inertiajs/react'?inertia:module.require(name);m._compile(code,m.filename);return m.exports.default;}
const Personal=compile('resources/js/components/immigration/case-profile/tabs/PersonalTab.jsx');
const Header=compile('resources/js/components/immigration/case-profile/CaseProfileHeader.jsx');
const Cases=compile('resources/js/pages/portal/immigration/Cases.jsx');
const root=createRoot(document.getElementById('root'));
const lead={id:42,first_name:'Original',middle_name:'Middle',last_name:'Applicant',suffix:'Jr.',email:'synthetic@example.test',name:'Original Applicant',immigration_stage:'For Assessment'};
const render=(Page,props)=>act(async()=>root.render(React.createElement(Page,props)));
const click=element=>act(async()=>{assert.ok(element,'Expected control');element.click();});
const namedInput=value=>[...document.querySelectorAll('input')].find(input=>input.value===value);
const button=label=>[...document.querySelectorAll('button')].find(button=>button.textContent.trim()===label);
(async()=>{
 for(const [parts,expected] of [
  [{first_name:'Synthetic',middle_name:'Middle',last_name:'Applicant'},'Synthetic Middle Applicant'],
  [{first_name:'Synthetic',middle_name:null,last_name:'Applicant'},'Synthetic Applicant'],
  [{first_name:' Synthetic ',middle_name:'  ',last_name:' Applicant '},'Synthetic Applicant'],
  [{first_name:'Māia',middle_name:'de la Cruz',last_name:'O’Neill'},'Māia de la Cruz O’Neill'],
  [{middle_name:'Middle',last_name:'Applicant'},'Middle Applicant'],
  [{lead_id:'IC-SYNTHETIC'},'IC-SYNTHETIC'],
  [{},'Unnamed case'],
 ]){
  await render(Header,{lead:parts});
  assert.equal(document.querySelector('h1').textContent,expected,'Header renders all available name parts without blank gaps');
 }
 await render(Header,{lead:{first_name:'Synthetic',middle_name:'Updated middle',last_name:'Applicant'}});
 assert.equal(document.querySelector('h1').textContent,'Synthetic Updated middle Applicant','Refreshed saved props update the display');
 assert.equal(calls.length,0,'Name rendering does not mutate identity or permissions');
 for(const allowed of [false,true,false]){
  await render(Personal,{lead,canCorrectCaseNames:allowed});
  for(const value of ['Original','Middle','Applicant','Jr.'])assert.equal(namedInput(value).readOnly,!allowed,'Personal identity fields follow the server capability');
  assert.equal(namedInput(lead.email).readOnly,false,'Ordinary personal fields retain existing behavior');
  if(!allowed)assert.match(document.body.textContent,/explicit Name Correction grant/);
 }
 for(const allowed of [false,true]){
  await act(async()=>root.render(null));await render(Cases,{cases:[lead],canCorrectCaseNames:allowed,canManageCaseArchive:false});
  assert.equal(document.querySelector('a[href$="/archived"]'),null,'Name permission does not expose archive navigation');
  await click(document.querySelector('button[title="More actions"]'));
  assert.equal(button('Archive case'),undefined,'Name permission does not expose archive actions');
  await click(button('Edit case'));
  assert.equal(namedInput('Original').readOnly,!allowed,'List edit receives name capability independently of archive capability');
 }
 assert.equal(calls.length,0,'Reading/rendering capabilities never submits identity changes');
 await act(async()=>root.unmount());dom.window.close();console.log('Case name correction actual-page checks passed: per-field locks, granted/revoked capability, Personal/list-edit consistency, unchanged other fields and no archive exposure or automatic writes');
})().catch(async error=>{console.error(error);await act(async()=>root.unmount());dom.window.close();process.exitCode=1;});
