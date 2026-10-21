import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

const bundle = await build({
    stdin: { contents: `import React from 'react'; import {createRoot} from 'react-dom/client';
        import DocumentsTab from './resources/js/components/immigration/case-profile/tabs/DocumentsTab.jsx';
        const root=createRoot(document.getElementById('root'));
        window.renderDocuments=(threads=[])=>root.render(React.createElement(DocumentsTab,{
            lead:{id:4}, documentRequests:[{id:6,origin:'rfi',label:'Requested evidence',latest_document:{id:9,original_name:'evidence.pdf',mime:'application/pdf',status:'Submitted'}}],
            caseStaff:[{id:2,name:'Case worker'}],threads
        }));`, resolveDir: process.cwd(), loader: 'jsx' },
    bundle:true,write:false,format:'iife',jsx:'automatic',alias:{'@':'./resources/js'},
    plugins:[{name:'test-boundaries',setup(b){
        b.onResolve({filter:/^(@inertiajs\/react|sonner|@\/components\/immigration\/CaseFilesModal|@\/pages\/portal\/immigration\/Assessments|@\/components\/immigration\/case-profile\/threads)$/},a=>({path:a.path,namespace:'test'}));
        b.onLoad({filter:/.*/,namespace:'test'},a=>({contents:
            a.path==='@inertiajs/react' ? `export const router={}; export const usePage=()=>({url:'/portal/immigration/cases/4',props:{auth:{user:{id:2,role:'immigration'}}}});`
            :a.path==='sonner' ? 'export const toast={success(){},error(){}};'
            :a.path.endsWith('/threads') ? `import React from 'react'; export const ThreadItem=({thread})=>React.createElement('p',null,thread.body); export const ThreadComposer=({caseStaff})=>React.createElement('p',null,'Staff: '+caseStaff.map(s=>s.name).join(', '));`
            :a.path.endsWith('/Assessments') ? 'export const IntakeViewModal=()=>null;'
            :'export default ()=>null;',loader:'js',resolveDir:process.cwd()}));
    }}],
});

test('RFI file preview displays persisted comments and receives refreshed comments and staff',async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try {
        const page=await browser.newPage();
        await page.setContent('<div id="root"></div>');
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        const comment={id:1,anchor_type:'document',anchor_id:9,body:'Existing evidence note'};
        await page.evaluate(t=>window.renderDocuments([t]),comment);
        await page.getByText('Request Information',{exact:true}).click();
        await page.getByTitle('View & comment',{exact:true}).click();
        assert.equal(await page.getByText(comment.body,{exact:true}).count(),1);
        assert.equal(await page.getByText('Staff: Case worker',{exact:true}).count(),1);
        await page.evaluate(t=>window.renderDocuments([t,{...t,id:2,body:'Newly saved evidence note'}]),comment);
        await page.getByText('Newly saved evidence note',{exact:true}).waitFor();
        assert.equal(await page.getByText('No comments yet. Add the first below.').count(),0);
    } finally {await browser.close();}
});
