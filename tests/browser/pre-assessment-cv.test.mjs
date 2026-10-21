import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';
const bundle=await build({stdin:{contents:`import React from 'react';import{createRoot}from'react-dom/client';import{StepDeclaration}from'./resources/js/pages/free-assessment/FreeAssessmentPage.jsx';function App(){const[data,setData]=React.useState({cv_files:[],declaration_accepted:false});window.files=data.cv_files;return <StepDeclaration data={data} setData={(k,v)=>setData(d=>({...d,[k]:v}))} errors={{'cv_files.0':'Invalid CV format'}} includeCv/>}createRoot(document.getElementById('root')).render(<App/>);`,resolveDir:process.cwd(),loader:'jsx'},bundle:true,write:false,format:'iife',jsx:'automatic',alias:{'@':process.cwd()+'/resources/js','@assets':process.cwd()+'/resources/assets'},loader:{'.png':'dataurl','.jpg':'dataurl','.svg':'dataurl'},plugins:[{name:'layout',setup(b){b.onResolve({filter:/^@\/components\/layout\/(Navbar|Footer)$/},a=>({path:a.path,namespace:'stub'}));b.onLoad({filter:/.*/,namespace:'stub'},()=>({contents:'export default ()=>null;'}));}}]});
test('pre-assessment declaration accepts and removes an optional CV and shows nested upload errors',async()=>{
 const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
 try{const page=await browser.newPage();await page.setContent('<div id="root"></div>');await page.addScriptTag({content:bundle.outputFiles[0].text});
 assert.equal(await page.locator('input[type=file]').count(),1);
 assert.equal(await page.getByText('Invalid CV format',{exact:true}).count(),1);
 await page.locator('input[type=file]').setInputFiles({name:'applicant-cv.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 synthetic CV')});
 assert.deepEqual(await page.evaluate(()=>window.files.map(f=>f.name)),['applicant-cv.pdf']);
 await page.getByRole('button',{name:'Remove file'}).click();assert.equal(await page.evaluate(()=>window.files.length),0);
 }finally{await browser.close();}
});
