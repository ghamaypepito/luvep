import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';

const program={id:7,title:'Original program',level:5,category:'diplomas',status:'draft',
    description:'Saved description',english_requirements:'IELTS 6.0',specialization:'Nursing',
    post_study:'Work rights',accommodation:'Homestay',credits:120,residency_points:10,hours_per_week:'20',
    insurance_fee:700,visa_processing_fee:500,living_expense:20000,
    entry_requirements:[{intro:'Entry intro',bullets:['A','B']}],
    employment_outcomes:[{intro:'Jobs',bullets:['Nurse']}],other_benefits:['Benefit one'],
    fee_guide:[{region:'India & Subcontinent',fee:100}],tuition_fees:[{label:'Year 1',amount:'1000',notes:'per year'}]};
const bundle=await build({stdin:{contents:`import React from 'react'; import {createRoot} from 'react-dom/client';
    import {router} from '@inertiajs/react'; import {ProgramModal} from './resources/js/pages/admin/Programs.jsx';
    router.post=(url,data)=>{window.saved={url,data}};
    window.renderProgram=p=>createRoot(document.getElementById('root')).render(React.createElement(ProgramModal,{open:true,editing:p,onClose(){}}));`,
    resolveDir:process.cwd(),loader:'jsx'},bundle:true,write:false,format:'iife',jsx:'automatic'});

test('editing only the title preserves every other populated program detail in the submitted form',async()=>{
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try{
        const page=await browser.newPage();
        await page.setContent('<div id="root"></div>');
        await page.addScriptTag({content:bundle.outputFiles[0].text});
        await page.evaluate(p=>window.renderProgram(p),program);
        await page.getByPlaceholder('e.g. NZ Diploma in Enrolled Nursing').fill('Edited program');
        await page.getByRole('button',{name:'Next: Details'}).click();
        await page.getByRole('button',{name:'Update Program'}).click();
        const saved=await page.evaluate(()=>window.saved);
        assert.equal(saved.url,'/admin/programs/7');
        assert.equal(saved.data.title,'Edited program');
        for(const [key,value] of Object.entries(program)){
            if(key!=='title')assert.deepEqual(saved.data[key],value,`${key} must survive a title edit`);
        }
    }finally{await browser.close();}
});
