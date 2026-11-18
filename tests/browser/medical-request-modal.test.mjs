import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

const bundle=await build({
    stdin:{contents:`import React from 'react'; import {createRoot} from 'react-dom/client';
        import {RequestInfoModal} from './resources/js/pages/portal/immigration/Cases.jsx';
        window.calls=[];window.errors=[];
        createRoot(document.getElementById('root')).render(React.createElement(RequestInfoModal,{caseId:4,medical:true,onClose:()=>{}}));`,resolveDir:process.cwd(),loader:'jsx'},
    bundle:true,write:false,format:'iife',jsx:'automatic',alias:{'@':'./resources/js'},
    plugins:[{name:'test-transport',setup(b){
        b.onResolve({filter:/^(@inertiajs\/react|sonner|@\/components\/immigration\/CaseFilesModal)$/},a=>({path:a.path,namespace:'test'}));
        b.onLoad({filter:/.*/,namespace:'test'},a=>({contents:a.path==='@inertiajs/react'
            ? `export const Head=()=>null,Link=()=>null,useForm=()=>({}); export const router={post:(url,data,options)=>{window.calls.push({url,data:{...data,...(data.documents?{documents:data.documents.map(f=>f.name)}:{})}});window.lastOptions=options;}};`
            :a.path==='sonner'?'export const toast={success(){},error:(message)=>window.errors.push(message)};':'export default ()=>null;',loader:'js'}));
    }}],
});

test('medical modal requires a deadline and PDF, posts medical route without AI, and retries email only',async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try {
        const page=await browser.newPage();
        await page.setContent('<div id="root"></div>');
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        assert.equal(await page.getByRole('heading',{name:'Request for Medical',exact:true}).count(),1);
        const submit=page.getByRole('button',{name:'Move to Request for Medical',exact:true});
        await submit.click();
        assert.equal(await page.evaluate(()=>window.calls.length),0);
        await page.locator('input[type=date]').fill('2027-01-15');
        await submit.click();
        assert.equal(await page.evaluate(()=>window.calls.length),0);
        await page.locator('input[type=file]').setInputFiles({name:'inz-notice.pdf',mimeType:'application/pdf',buffer:Buffer.from('%PDF-1.4 test')});
        assert.equal(await page.getByText('Documents requested by INZ',{exact:true}).count(),0);
        await submit.click();
        const calls=await page.evaluate(()=>window.calls);
        assert.equal(calls[0].url,'/portal/immigration/cases/4/medical');
        assert.deepEqual(calls[0].data.documents,['inz-notice.pdf']);
        assert.equal(calls[0].data.deadline,'2027-01-15');
        assert.equal(calls[0].data.notify,1);
        assert.equal('requested_documents' in calls[0].data,false);
        await page.evaluate(()=>{window.lastOptions.onError({notification:'Saved, email failed'});window.lastOptions.onFinish();});
        await page.getByRole('button',{name:'Retry notification',exact:true}).click();
        assert.deepEqual((await page.evaluate(()=>window.calls))[1],{url:'/portal/immigration/cases/4/medical',data:{retry_notification:1}});
    } finally {await browser.close();}
});
