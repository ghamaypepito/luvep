import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

const bundle = await build({
    stdin: { contents: `import React from 'react'; import {createRoot} from 'react-dom/client';
        import Tab from './resources/js/components/immigration/case-profile/tabs/CommunicationsTab.jsx';
        window.calls=[]; window.fetch=async(url,options={})=>{window.calls.push({url,method:options.method||'GET',body:options.body instanceof FormData?Object.fromEntries(options.body):options.body}); return {ok:true,json:async()=>({ok:true,can_email:true,lead_email:'case@example.test',data:[{id:2,key:'i2',direction:window.messageDirection||'in',channel:'email',subject:'Visa question',body:window.inboundBody||'Please help',from:'case@example.test',created_at:'2026-10-20',status:'received'}]})};};
        createRoot(document.getElementById('root')).render(<Tab lead={{id:7,email:'case@example.test',first_name:'Case'}} clientReplies={[{id:2,subject:'Visa question',body:'Please help',received_at:'2026-10-20'}]}/>);`, resolveDir: process.cwd(), loader: 'jsx' },
    bundle:true,write:false,format:'iife',jsx:'automatic', alias:{'@':`${process.cwd()}/resources/js`},
    plugins:[{name:'test-transport',setup(b){
        b.onResolve({filter:/^(@inertiajs\/react|sonner|@\/components\/templates\/RichTextEditor)$/},a=>({path:a.path,namespace:'test'}));
        b.onLoad({filter:/.*/,namespace:'test'},a=>({contents:a.path==='@inertiajs/react'?'export const router={reload(){}};':a.path==='sonner'?'export const toast={success(){},error(){}};':`import React from 'react'; export default function Editor({value,onChange}){return React.createElement('textarea',{'aria-label':'Message body',value,onChange:e=>onChange(e.target.value)});}`,loader:'js',resolveDir:process.cwd()}));
    }}],
});

test('immigration uses the Education conversation reply flow without a real email',async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try {
        const page=await browser.newPage(); page.setDefaultTimeout(5000); page.on('pageerror',e=>console.error(e.message));
        await page.route('http://conversation.test/**', route=>route.fulfill({contentType:'text/html',body:'<div id="root"></div>'}));
        await page.goto('http://conversation.test/portal/immigration/cases/7/profile');
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        await page.getByText('Visa question',{exact:true}).first().click();
        await page.getByRole('button',{name:'Reply to this email',exact:true}).click({timeout:3000});
        await page.getByLabel('Message body').fill('Synthetic reply');
        await page.getByRole('button',{name:/Send email/i}).click();
        await page.waitForFunction(()=>window.calls.some(c=>c.method==='POST'));
        const sent=await page.evaluate(()=>window.calls.find(c=>c.method==='POST'));
        assert.equal(sent.url,'/admin/leads/7/communications');
        assert.equal(sent.body.subject,'Re: Visa question');
        assert.equal(sent.body.body,'Synthetic reply');
    } finally {await browser.close();}
});

test('agent case history stays read-only without opening staff communication endpoints',async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try {
        const page=await browser.newPage(); page.setDefaultTimeout(5000);
        await page.route('http://conversation.test/**',route=>route.fulfill({contentType:'text/html',body:'<div id="root"></div>'}));
        await page.goto('http://conversation.test/portal/agent/cases/7/profile');
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        await page.getByText('Please help',{exact:true}).first().waitFor();
        assert.equal(await page.getByRole('button',{name:'Send email',exact:true}).count(),0);
        assert.equal(await page.getByRole('button',{name:'New email',exact:true}).count(),0);
        assert.deepEqual(await page.evaluate(()=>window.calls),[]);
    } finally {await browser.close();}
});

test('inbound email text is escaped even when it looks like active HTML',async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try {
        const page=await browser.newPage(); page.setDefaultTimeout(5000);
        await page.route('http://conversation.test/**',route=>route.fulfill({contentType:'text/html',body:'<div id="root"></div>'}));
        await page.goto('http://conversation.test/portal/immigration/cases/7/profile');
        const payload='<div><img src="bad" onerror="window.inboundExecuted=true">Private email text</div>';
        await page.evaluate(body=>{window.inboundBody=body;},payload);
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        await page.getByText('Visa question',{exact:true}).first().click();
        await page.getByRole('button',{name:'Reply to this email',exact:true}).waitFor();
        assert.equal(await page.locator('img[onerror]').count(),0);
        assert.equal(await page.getByText(payload,{exact:true}).count(),1);
        assert.equal(await page.evaluate(()=>window.inboundExecuted),undefined);
    } finally {await browser.close();}
});

test('outbound HTML is isolated in a sandbox without script permission',async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try {
        const page=await browser.newPage(); page.setDefaultTimeout(5000);
        await page.route('http://conversation.test/**',route=>route.fulfill({contentType:'text/html',body:'<div id="root"></div>'}));
        await page.goto('http://conversation.test/portal/immigration/cases/7/profile');
        await page.evaluate(()=>{window.messageDirection='out';window.inboundBody='<div><img src="bad" onerror="parent.outboundExecuted=true">Outgoing</div>';});
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        await page.getByText('Visa question',{exact:true}).first().click();
        await page.getByRole('button',{name:'Reply to this email',exact:true}).waitFor();
        assert.equal(await page.locator('iframe[sandbox]').count(),1);
        assert.equal(await page.locator('iframe').getAttribute('sandbox'),'');
        assert.equal(await page.locator('img[onerror]').count(),0);
        assert.equal(await page.evaluate(()=>window.outboundExecuted),undefined);
    } finally {await browser.close();}
});
