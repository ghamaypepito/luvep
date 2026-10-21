import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

// Render the real menu; intercept only its transport and unrelated viewer threads.
const bundle = await build({
    stdin: {
        contents: `import React from 'react'; import {createRoot} from 'react-dom/client';
            import {LeadDocFileMenu} from './resources/js/components/ui/LeadDocViewerModal.jsx';
            window.calls=[];
            window.renderMenu=(options={})=>createRoot(document.getElementById('root')).render(
                React.createElement(LeadDocFileMenu, {doc:{id:9,original_name:'wrong.pdf',status:'Submitted'},leadId:4,checklistKey:'acad.cv',...options}));`,
        resolveDir: process.cwd(), loader: 'jsx',
    },
    bundle: true, write: false, format: 'iife', jsx: 'automatic',
    plugins: [{ name: 'test-transport', setup(b) {
        b.onResolve({filter: /^(@inertiajs\/react|sonner|@\/components\/immigration\/case-profile\/threads)$/}, a => ({path:a.path, namespace:'test'}));
        b.onLoad({filter:/.*/,namespace:'test'}, a => ({contents:
            a.path === '@inertiajs/react' ? `export const router={post:(url,data,options)=>window.calls.push({method:'POST',url,data,only:options.only}),delete:(url,options)=>window.calls.push({method:'DELETE',url,only:options.only})};`
            : a.path === 'sonner' ? 'export const toast={success(){},error(){}};'
            : 'export const ThreadItem=()=>null; export const ThreadComposer=()=>null;', loader:'js'}));
    }}],
});

async function withMenu(options, run) {
    const browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL ? {channel:process.env.PLAYWRIGHT_CHANNEL} : {})});
    try {
        const page = await browser.newPage();
        await page.setContent('<div id="root"></div>');
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        await page.evaluate(o=>window.renderMenu(o),options);
        await page.getByTitle('More',{exact:true}).click();
        await run(page);
    } finally { await browser.close(); }
}

test('explicitly permitted removal confirms and uses the scoped lead route', async()=>{
    await withMenu({canDelete:true,basePath:'/portal/agent/leads',only:['checklistFiles']},async page=>{
        assert.equal(await page.getByRole('button',{name:'Delete file',exact:true}).count(),1);
        page.once('dialog', d=>d.dismiss());
        await page.getByRole('button',{name:'Delete file',exact:true}).click();
        assert.deepEqual(await page.evaluate(()=>window.calls),[]);
        page.once('dialog', d=>d.accept());
        await page.getByRole('button',{name:'Delete file',exact:true}).click();
        assert.deepEqual(await page.evaluate(()=>window.calls),[{method:'DELETE',url:'/portal/agent/leads/4/documents/9',only:['checklistFiles']}]);
    });
});

test('admin rejection sends a reason using the existing review endpoint',async()=>{
    await withMenu({canReject:true},async page=>{
        assert.equal(await page.getByRole('button',{name:'Reject file',exact:true}).count(),1);
        page.once('dialog',d=>d.accept('Wrong assessment document'));
        await page.getByRole('button',{name:'Reject file',exact:true}).click();
        assert.deepEqual(await page.evaluate(()=>window.calls),[{method:'POST',url:'/admin/leads/4/documents/9/status',data:{status:'Rejected',note:'Wrong assessment document'},only:['documents']}]);
    });
});

test('destructive review actions stay hidden unless explicitly permitted',async()=>{
    await withMenu({},async page=>{
        assert.equal(await page.getByRole('button',{name:'Delete file',exact:true}).count(),0);
        assert.equal(await page.getByRole('button',{name:'Reject file',exact:true}).count(),0);
    });
});

for (const height of [220, 110]) {
    test(`bottom-edge document menu remains usable in a ${height}px viewport`, async()=>{
        await withMenu({canDelete:true,canReject:true},async page=>{
            await page.setViewportSize({width:320,height});
            await page.evaluate(()=>{
                const root=document.getElementById('root');
                root.style.position='fixed'; root.style.bottom='4px'; root.style.right='4px';
                window.dispatchEvent(new Event('resize'));
            });
            const button=page.getByRole('button',{name:'Delete file',exact:true});
            await button.scrollIntoViewIfNeeded();
            const box=await button.boundingBox();
            assert.ok(box && box.y >= 0 && box.y+box.height <= height, 'Delete must remain inside viewport');
            page.once('dialog',d=>d.accept());
            await button.click();
            assert.equal((await page.evaluate(()=>window.calls))[0]?.method,'DELETE');
        });
    });
}
