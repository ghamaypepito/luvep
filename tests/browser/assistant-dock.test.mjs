import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
const bundle=await build({stdin:{contents:`import React from 'react'; import {createRoot} from 'react-dom/client'; import {router} from '@inertiajs/react'; import Dock from './resources/js/components/ai/AssistantDock.jsx'; const page={url:'/admin/dashboard',props:{auth:{user:{id:1,role:'admin'},ai_enabled:true}}}; router.page=page; router.on=(name,fn)=>{window.navigate=p=>fn({detail:{page:p}});return ()=>{};}; window.fetch=async url=>({ok:true,json:async()=>url.includes('subject_id')?{aiEnabled:true,subject:{id:12,type:'lead',label:'Test record'}}:url.endsWith('/9')?{conversation:{id:9},messages:[{id:1,role:'assistant',content:'Saved response'}]}:{conversations:[{id:9,title:'Saved conversation'}]}});createRoot(document.getElementById('root')).render(<Dock initialPage={page}/>);`,resolveDir:process.cwd(),loader:'jsx'},alias:{'@':process.cwd()+'/resources/js'},bundle:true,write:false,format:'iife',jsx:'automatic'});
const manifest=JSON.parse(readFileSync('public/build/manifest.json','utf8'));
const css=readFileSync('public/build/'+manifest['style.css'].file,'utf8');
async function run(fn, viewport={width:1280,height:900}) {const browser=await chromium.launch({headless:true,channel:process.env.PLAYWRIGHT_CHANNEL||'chrome'});try{const page=await browser.newPage({viewport});await page.setContent('<div id="root"></div>');await page.addStyleTag({content:css});await page.addScriptTag({content:bundle.outputFiles[0].text});await page.waitForFunction(()=>typeof window.navigate==='function');await fn(page);}finally{await browser.close();}}
test('draft survives minimizing and internal navigation; account change clears chat',()=>run(async p=>{
 await p.getByRole('button',{name:'Open EP Assistant',exact:true}).click();
 await p.getByRole('textbox').fill('Draft message');
 await p.getByRole('button',{name:'Minimize EP Assistant'}).click();
 await p.evaluate(()=>window.navigate({url:'/portal/hr/dashboard',props:{auth:{user:{id:1,role:'admin'},ai_enabled:true}}}));
 await p.getByRole('button',{name:'Open EP Assistant',exact:true}).click();
 assert.equal(await p.getByRole('textbox').inputValue(),'Draft message');
 await p.evaluate(()=>window.navigate({url:'/admin/dashboard',props:{auth:{user:{id:2,role:'admin'},ai_enabled:true}}}));
 await p.getByRole('button',{name:'Open EP Assistant',exact:true}).click();
 assert.equal(await p.getByRole('textbox').inputValue(),'');
}));
test('history opens saved conversation and expansion keeps its id',()=>run(async p=>{
 await p.getByRole('button',{name:'Open EP Assistant',exact:true}).click();
 await p.getByRole('button',{name:'Conversation history'}).click();
 await p.getByRole('button',{name:'Saved conversation'}).click();
 await p.getByText('Saved response').waitFor();
 await p.waitForFunction(()=>document.querySelector('[aria-label="Expand EP Assistant"]').getAttribute('href').includes('conversation_id')); 
 assert.equal(await p.getByRole('link',{name:'Expand EP Assistant'}).getAttribute('href'),'/assistant?conversation_id=9');
 await p.getByRole('textbox').press('Escape');
 assert.equal(await p.getByRole('dialog').count(),0);
}));
test('client, disabled AI and logout remove the dock',()=>run(async p=>{
 for (const auth of [{user:{id:2,role:'lead'},ai_enabled:true},{user:{id:1,role:'admin'},ai_enabled:false},null]) {
 await p.evaluate(auth=>window.navigate({url:'/admin/dashboard',props:{auth}}),auth);
 await p.waitForTimeout(30);
 assert.equal(await p.getByRole('button',{name:'Open EP Assistant',exact:true}).count(),0);
 }
}));
test('mobile panel fills viewport and record trigger gets authorized context',()=>run(async p=>{
 await p.getByRole('button',{name:'Open EP Assistant',exact:true}).waitFor();
 await p.waitForTimeout(50);
 await p.evaluate(()=>window.dispatchEvent(new CustomEvent('ep-assistant-open',{detail:{subjectId:12}})));
 await p.getByText('About: Test record').waitFor();
 const box=await p.getByRole('dialog').boundingBox();
 assert.equal(box.width,390);assert.equal(box.height,844);
 assert.equal(await p.getByRole('link',{name:'Expand EP Assistant'}).getAttribute('href'),'/assistant?subject_id=12');
 await p.screenshot({path:'/private/tmp/assistant-mobile.png'});
},{width:390,height:844}));

test('reopening a record preserves its draft; expansion cannot discard it',()=>run(async p=>{
 await p.getByRole('button',{name:'Open EP Assistant',exact:true}).waitFor();
 await p.waitForTimeout(50);
 const openRecord=()=>p.evaluate(()=>window.dispatchEvent(new CustomEvent('ep-assistant-open',{detail:{subjectId:12}})));
 await openRecord();
 await p.getByRole('textbox').fill('Keep this draft');
 await openRecord();
 await p.getByText('About: Test record').waitFor();
 assert.equal(await p.getByRole('textbox').inputValue(),'Keep this draft');
 await p.getByRole('link',{name:'Expand EP Assistant'}).dispatchEvent('click');
 await p.getByRole('alert').filter({hasText:'Send or clear'}).waitFor();
 assert.equal(await p.getByRole('textbox').inputValue(),'Keep this draft');
 await p.evaluate(()=>{window.fetch=async()=>{throw new Error('Offline');};window.dispatchEvent(new CustomEvent('ep-assistant-open',{detail:{subjectId:13}}));});
 await p.getByRole('alert').filter({hasText:'Could not open'}).waitFor();
 assert.equal(await p.getByRole('textbox').inputValue(),'Keep this draft');
}));
