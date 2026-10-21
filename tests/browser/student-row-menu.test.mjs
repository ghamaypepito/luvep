import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

// Expose the real private component for a focused browser render.
const bundle = await build({
    stdin: { contents: `import React from 'react'; import {createRoot} from 'react-dom/client';
        import {RowMenu} from './resources/js/pages/portal/education/Students.jsx';
        window.selected=false;
        createRoot(document.getElementById('root')).render(React.createElement(RowMenu,{items:
            Array.from({length:8},(_,i)=>({key:i,label:i===7?'Edit student':'Action '+i,onClick:()=>window.selected=true}))}));`,
        resolveDir: process.cwd(), loader: 'jsx' },
    bundle: true, write: false, format: 'iife', jsx: 'automatic',
    alias: {'@': resolve('resources/js')},
    plugins: [{name:'expose-menu',setup(b){
        b.onLoad({filter:/portal\/education\/Students\.jsx$/},async args=>({
            contents:(await readFile(args.path,'utf8'))+'\nexport {RowMenu};',loader:'jsx'
        }));
    }}],
});

for(const height of [400,110]){
    test(`student menu actions remain reachable at bottom of ${height}px viewport`,async()=>{
        const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
        try{
            const page=await browser.newPage({viewport:{width:320,height}});
            await page.setContent(`<style>body{margin:0}#root{position:fixed;right:8px;bottom:8px}[role=menu] button{display:block;width:100%;height:32px}</style><div id="root"></div>`);
            await page.addScriptTag({content:bundle.outputFiles[0].text});
            await page.getByTitle('More actions').click();
            const menu=page.getByRole('menu');
            await menu.waitFor();
            const box=await menu.boundingBox();
            assert.ok(box && box.y>=0 && box.y+box.height<=height,'All menu content must fit or scroll within the viewport');
            const edit=page.getByRole('button',{name:'Edit student',exact:true});
            await edit.scrollIntoViewIfNeeded();
            await edit.click();
            assert.equal(await page.evaluate(()=>window.selected),true);
        }finally{await browser.close();}
    });
}
