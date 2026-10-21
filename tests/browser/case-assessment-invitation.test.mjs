import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

const bundle=await build({stdin:{contents:`import React from 'react';import {createRoot} from 'react-dom/client';
    import {router} from '@inertiajs/react';import PersonalTab from './resources/js/components/immigration/case-profile/tabs/PersonalTab.jsx';
    window.calls=[];router.post=(url,data,options)=>{window.calls.push({url,data});window.finish=()=>{options.onSuccess?.({props:{flash:{success:'Assessment prepared'}}});options.onFinish?.()}};
    window.renderCase=role=>{window.role=role;createRoot(document.getElementById('root')).render(React.createElement(PersonalTab,{lead:{id:42,first_name:'Synthetic',last_name:'Case'}}))};`,resolveDir:process.cwd(),loader:'jsx'},
    bundle:true,write:false,format:'iife',jsx:'automatic',alias:{'@':resolve('resources/js')},
    plugins:[{name:'portal-context',setup(b){b.onResolve({filter:/^@inertiajs\/react$/},()=>({path:'inertia-context',namespace:'test'}));
        b.onLoad({filter:/.*/,namespace:'test'},()=>({contents:`export * from ${JSON.stringify(resolve('node_modules/@inertiajs/react/dist/index.esm.js'))};export const usePage=()=>({props:{auth:{user:{role:window.role}}}});`,resolveDir:process.cwd(),loader:'js'}));}}]});

for(const role of ['admin','agent'])test(`assessment invitation in active Personal tab respects ${role} access`,async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try{
        const page=await browser.newPage();await page.setContent('<div id="root"></div>');await page.addScriptTag({content:bundle.outputFiles[0].text});
        await page.evaluate(role=>window.renderCase(role),role);
        await page.getByText('No visa assessment on file').waitFor();
        const button=page.getByRole('button',{name:'Send Assessment Form',exact:true});
        assert.equal(await button.count(),role==='admin'?1:0);
        if(role==='admin'){
            await button.click();
            assert.equal(await page.getByRole('button',{name:'Preparing…'}).isDisabled(),true);
            await page.evaluate(()=>document.querySelector('button:disabled')?.click());
            assert.deepEqual(await page.evaluate(()=>window.calls),[{url:'/portal/immigration/cases/42/assessment-invitation',data:{}}]);
            await page.evaluate(()=>window.finish());
            await button.waitFor();
            assert.equal(await button.isEnabled(),true);
        }
    }finally{await browser.close();}
});
