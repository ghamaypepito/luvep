import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

const bundle=await build({stdin:{contents:`import React from 'react';import {createRoot} from 'react-dom/client';
 import {router} from '@inertiajs/react';import AddEditStudentModal from './resources/js/pages/portal/education/AddEditStudentModal.jsx';
 window.calls=[];router.post=(url,data,options)=>{window.calls.push({url,data});options.onFinish?.()};
 createRoot(document.getElementById('root')).render(React.createElement(AddEditStudentModal,{open:true,onClose(){},student:{id:12,first_name:'Synthetic',last_name:'Student',email:'course@example.test',phone:'+639000000000',education_stage:'Started Course',intake:'2026-09-01',actual_course_started_on:'2026-09-03'}}));`,resolveDir:process.cwd(),loader:'jsx'},bundle:true,write:false,format:'iife',jsx:'automatic'});

test('student editor seeds, changes and clears actual start independently of planned intake',async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{
  const page=await browser.newPage();await page.setContent('<div id="root"></div>');await page.addScriptTag({content:bundle.outputFiles[0].text});
  await page.getByRole('button',{name:'Save changes',exact:true}).waitFor();
  const actual=page.getByLabel('Actual course start date',{exact:true});
  assert.equal(await actual.count(),1);
  assert.equal(await actual.inputValue(),'2026-09-03');
  await actual.fill('2026-09-04');
  await page.getByRole('button',{name:'Save changes',exact:true}).click();
  let calls=await page.evaluate(()=>window.calls);
  assert.equal(calls[0].data.actual_course_started_on,'2026-09-04');
  assert.equal(calls[0].data.intake,'2026-09-01');
  assert.equal(calls[0].data.education_stage,'Started Course');
  await actual.fill('');await page.getByRole('button',{name:'Save changes',exact:true}).click();
  calls=await page.evaluate(()=>window.calls);
  assert.equal(calls[1].data.actual_course_started_on,null);
  assert.equal(calls[1].data.intake,'2026-09-01');
 }finally{await browser.close();}
});
