import { test } from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { chromium } from '@playwright/test';
import { resolve } from 'node:path';
import { readFile, mkdir } from 'node:fs/promises';

let compiledCss = '';
try {
    const manifest = JSON.parse(await readFile('public/build/manifest.json', 'utf8'));
    compiledCss = await readFile(resolve('public/build', manifest['style.css'].file), 'utf8');
} catch { /* Interaction tests can run before building; screenshots require the build. */ }

const pathways = [
    {key:'education',label:'Student',active:false,stage:null,stages:['New Lead','For Proposal','Proposal Sent'],default_stage:'New Lead',assignees:[],assignee:null,open_url:null},
    {key:'immigration',label:'Immigration',active:true,stage:'Approved Visa',stages:['For Assessment','Approved Visa'],default_stage:'Approved Visa',assignees:['Hendry','Tarun'],assignee:'Hendry',open_url:'/portal/immigration/cases/4/profile'},
    {key:'english',label:'English',active:false,stage:null,stages:['PTE Review','DIY Review'],default_stage:'PTE Review',assignees:['Paula','Frank','DIY'],assignee:null,open_url:null},
    {key:'accommodation',label:'Accommodation',active:false,stage:null,stages:[],default_stage:null,assignees:[],assignee:null,open_url:null},
];
const bundle = await build({
    stdin:{contents:`import React from 'react'; import {createRoot} from 'react-dom/client';
        import AddPathwayButton from './resources/js/components/leads/AddPathwayButton.jsx';
        createRoot(document.getElementById('root')).render(<AddPathwayButton leadId={4}/>);`,resolveDir:process.cwd(),loader:'jsx'},
    bundle:true,write:false,format:'iife',jsx:'automatic',alias:{'@':resolve('resources/js')},
    plugins:[{name:'inertia-test-transport',setup(b){
        b.onResolve({filter:/^@inertiajs\/react$/},a=>({path:a.path,namespace:'test'}));
        b.onLoad({filter:/.*/,namespace:'test'},()=>({contents:`import React from 'react';
            export const Link=({href,children,...props})=>React.createElement('a',{href,...props},children);
            export const useForm=(initial)=>{
                const [data,set]=React.useState(initial),[errors,setErrors]=React.useState({}),[processing,setProcessing]=React.useState(false);
                return {data,errors,processing,setData:(key,value)=>set(d=>typeof key==='object'?key:{...d,[key]:value}),clearErrors:()=>setErrors({}),reset:()=>set(initial),
                    post:async(url,options)=>{setProcessing(true);try{const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});
                        const body=await r.json();if(r.ok){options.onSuccess?.();}else{setErrors(body.errors||{});options.onError?.(body.errors||{});}
                    }finally{setProcessing(false);options.onFinish?.();}}};};`,loader:'js',resolveDir:process.cwd()}));
    }}],
});

async function setup({width=1280,failLoad=false,postStatus=200,holdPost=false}={}) {
    const browser = await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    const page = await browser.newPage({viewport:{width,height:800}});
    const calls=[];
    let releasePost;
    const postGate = new Promise(resolve => { releasePost = resolve; });
    let getCount=0;
    await page.route('http://pathway.test/**',async route=>{
        const req=route.request();
        if(req.url().endsWith('/clients/4/pathways')){
            if(req.method()==='GET'){
                getCount++;
                await route.fulfill({status:failLoad&&getCount===1?500:200,contentType:'application/json',body:JSON.stringify({pathways})});
            }else{
                calls.push(req.postDataJSON());
                if (holdPost) await postGate;
                await route.fulfill({status:postStatus,contentType:'application/json',body:JSON.stringify(postStatus===200?{}:{errors:{stage:'Choose a valid starting stage.'}})});
            }
        }else await route.fulfill({contentType:'text/html',body:'<div id="root"></div>'});
    });
    await page.goto('http://pathway.test/');
    if (compiledCss) await page.addStyleTag({content:compiledCss});
    await page.addScriptTag({content:bundle.outputFiles[0].text});
    return {browser,page,calls,releasePost};
}

test('select Student pathway at Proposal Sent and submit without changing Immigration',async()=>{
    const {browser,page,calls}=await setup();
    try{
        await page.getByRole('button',{name:'Add pathway',exact:true}).click();
        await page.getByLabel('Pathway',{exact:true}).selectOption('education');
        await page.getByLabel('Starting stage').selectOption('Proposal Sent');
        await page.getByLabel('Handoff note').fill('Client now wants to study.');
        await page.getByRole('button',{name:'Add Student pathway',exact:true}).click();
        await page.getByRole('dialog').waitFor({state:'hidden'});
        assert.deepEqual(calls,[{pathway:'education',stage:'Proposal Sent',assignee:'',note:'Client now wants to study.'}]);
    }finally{await browser.close();}
});

test('active pathway shows existing progress and never offers a reset',async()=>{
    const {browser,page,calls}=await setup();
    try{
        await page.getByRole('button',{name:'Add pathway',exact:true}).click();
        await page.getByLabel('Pathway',{exact:true}).selectOption('immigration');
        await page.getByRole('link',{name:'Open Immigration pathway'}).waitFor();
        assert.equal(await page.getByText('Approved Visa',{exact:true}).count(),1);
        assert.equal(await page.getByLabel('Starting stage').count(),0);
        assert.equal(await page.getByRole('button',{name:'Add Immigration pathway'}).count(),0);
        assert.deepEqual(calls,[]);
    }finally{await browser.close();}
});

test('load error can be retried and keyboard dismissal restores focus',async()=>{
    const {browser,page}=await setup({failLoad:true});
    try{
        const trigger=page.getByRole('button',{name:'Add pathway',exact:true});
        await trigger.click();
        await page.getByRole('button',{name:'Try again'}).click();
        await page.getByLabel('Pathway',{exact:true}).waitFor();
        await page.keyboard.press('Escape');
        await page.getByRole('dialog').waitFor({state:'hidden'});
        assert.equal(await trigger.evaluate(el=>document.activeElement===el),true);
    }finally{await browser.close();}
});

test('validation error stays in the form and switching pathway clears it',async()=>{
    const {browser,page}=await setup({postStatus:422});
    try{
        await page.getByRole('button',{name:'Add pathway',exact:true}).click();
        await page.getByLabel('Pathway',{exact:true}).selectOption('education');
        await page.getByRole('button',{name:'Add Student pathway',exact:true}).click();
        await page.getByText('Choose a valid starting stage.').waitFor();
        await page.getByLabel('Pathway',{exact:true}).selectOption('english');
        assert.equal(await page.getByText('Choose a valid starting stage.').count(),0);
        await page.getByLabel('Assigned adviser').selectOption('Paula');
        await page.getByLabel('Pathway',{exact:true}).selectOption('accommodation');
        assert.equal(await page.getByLabel('Starting stage').count(),0);
        assert.equal(await page.getByLabel('Assigned adviser').count(),0);
    }finally{await browser.close();}
});

test('pending submission blocks a second add and dismissal until it finishes',async()=>{
    const {browser,page,calls,releasePost}=await setup({holdPost:true});
    try{
        await page.getByRole('button',{name:'Add pathway',exact:true}).click();
        await page.getByRole('button',{name:'Add Student pathway',exact:true}).click();
        const pending=page.getByRole('button',{name:'Adding pathway…',exact:true});
        await pending.waitFor();
        assert.equal(await pending.isDisabled(),true);
        assert.equal(await page.getByRole('button',{name:'Close pathways'}).isDisabled(),true);
        await page.keyboard.press('Escape');
        assert.equal(await page.getByRole('dialog').isVisible(),true);
        releasePost();
        await page.getByRole('dialog').waitFor({state:'hidden'});
        assert.equal(calls.length,1);
    }finally{releasePost();await browser.close();}
});

for (const width of [1280, 390]) {
    test(`dialog fits ${width}px viewport and keyboard focus stays inside`, async()=>{
        const {browser,page}=await setup({width});
        try{
            await page.getByRole('button',{name:'Add pathway',exact:true}).click();
            await page.getByLabel('Pathway',{exact:true}).selectOption('education');
            await page.getByLabel('Starting stage').selectOption('Proposal Sent');
            const dialog=page.getByRole('dialog');
            const box=await dialog.boundingBox();
            assert.ok(box.x>=0&&box.x+box.width<=width,'Dialog must fit horizontally');
            assert.ok(box.y>=0&&box.y+box.height<=800,'Dialog must fit vertically');
            await page.getByRole('button',{name:'Add Student pathway',exact:true}).focus();
            await page.keyboard.press('Tab');
            assert.equal(await dialog.evaluate(el=>el.contains(document.activeElement)),true);
            assert.equal(await page.evaluate(()=>document.body.style.overflow),'hidden');
            if (process.env.PATHWAY_SCREENSHOT_DIR) {
                assert.ok(compiledCss.length>1000,'Screenshots must use the production CSS');
                await mkdir(process.env.PATHWAY_SCREENSHOT_DIR,{recursive:true});
                await page.screenshot({path:resolve(process.env.PATHWAY_SCREENSHOT_DIR,`add-pathway-${width}.png`),fullPage:true});
            }
        }finally{await browser.close();}
    });
}
