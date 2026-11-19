import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync } from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const assets=path.resolve('resources/social-workspace');
const sample={headline:'Your next chapter starts here.',supporting_copy:'Explore study planning with EP.',cta:'Talk to our team',caption:'Start with a conversation.',layout:'photo-top'};
async function withStudio(run){
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try{
        const page=await browser.newPage({viewport:{width:1440,height:1100}}),writes=[],errors=[];
        page.setDefaultTimeout(6000);page.on('pageerror',error=>errors.push(error.message));
        await page.route('**/*',async route=>{
            const request=route.request(),url=new URL(request.url());
            if(url.hostname!=='studio.test'){await route.abort();return;}
            if(url.pathname==='/social/workspace-test/ai/layout'){
                const data=request.postDataJSON();writes.push(data);
                await route.fulfill({json:{design:{...sample,captions:Object.fromEntries(data.channels.map(channel=>[channel,`${channel}: Explore your next step with EP. Talk to our team.`]))},draft:true}});return;
            }
            if(url.pathname==='/social/marketing/posts'){assert.equal(request.method(),'GET');await route.fulfill({json:{posts:[],can_approve:true,ghl_user_configured:true}});return;}
            if(url.pathname==='/social/ghl/accounts'){await route.fulfill({json:{publishing_enabled:true,accounts:[{selected:true,name:'Luv, Ep',platform:'facebook',active:true}]}});return;}
            if(url.pathname.startsWith('/social/ghl/')){await route.fulfill({json:{posts:[],page:1,has_more:false,total:0}});return;}
            if(url.pathname==='/'){
                await route.fulfill({contentType:'text/html',body:readFileSync(path.join(assets,'index.html'),'utf8').replace('<head>','<head><meta name="csrf-token" content="test-only">')});return;
            }
            const file=path.resolve(assets,'.'+decodeURIComponent(url.pathname));
            if(!file.startsWith(assets+path.sep)||!existsSync(file)){await route.fulfill({status:404,body:''});return;}
            const types={'.js':'text/javascript','.css':'text/css','.json':'application/json','.png':'image/png','.ttf':'font/ttf'};
            await route.fulfill({contentType:types[path.extname(file)]||'application/octet-stream',body:readFileSync(file)});
        });
        await page.goto('http://studio.test/');await page.locator('#marketing-workflow-loading').waitFor({state:'detached'});
        await run(page,writes);assert.deepEqual(errors,[]);
    }finally{await browser.close();}
}

test('one brief produces editable graphics and platform captions, exports a set and applies only a reviewed Facebook feed draft',async()=>{
    await withStudio(async(page,writes)=>{
        assert.equal(await page.locator('#campaign-fields').isVisible(),true);
        await page.locator('#campaign-name').fill('Plan your next chapter');
        await page.locator('#campaign-brief').fill('Introduce EP study planning support. Invite prospective students to start a conversation. Use warm, clear language.');
        for(const channel of ['TikTok','LinkedIn','YouTube','Pinterest','X','Threads'])await page.locator(`[name="compose-platform"][value="${channel}"]`).check();
        if(process.env.COMPOSE_PROOF_DIR){mkdirSync(process.env.COMPOSE_PROOF_DIR,{recursive:true});await page.screenshot({path:process.env.COMPOSE_PROOF_DIR+'/compose-preview.png',fullPage:true});}
        await page.getByRole('button',{name:'Generate graphics & platform captions',exact:true}).click();
        const dialog=page.getByRole('dialog',{name:'Your suggested post set'});await dialog.waitFor();
        await page.locator('#ep-layout-png:enabled').waitFor();assert.equal(writes.length,1);assert.equal(writes[0].channels.length,8);assert.equal(writes[0].photo,undefined);
        assert.equal(await page.locator('#ep-layout-channel option').count(),8);
        await page.locator('#ep-layout-channel').selectOption('X');await page.locator('#ep-layout-png:enabled').waitFor();
        assert.equal(await page.locator('#ep-layout-caption').inputValue(),'X: Explore your next step with EP. Talk to our team.');
        await page.locator('#ep-layout-caption').fill('A revised X caption.');
        await page.locator('#ep-layout-channel').selectOption('Facebook');await page.locator('#ep-layout-png:enabled').waitFor();
        await page.locator('#ep-layout-headline').fill('Your next chapter. Your way.');await page.locator('#ep-layout-png:enabled').waitFor();
        assert.equal(await page.locator('#ep-layout-canvas').getAttribute('width'),'1080');
        for(const width of [1440,1024,800,768,390,320]){
            await page.setViewportSize({width,height:1000});
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'Compose fits '+width);
            const box=await dialog.boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width,'Result fits '+width);
        }
        await page.setViewportSize({width:1280,height:720});
        const actions=await dialog.locator('.caption-suggestion-actions').boundingBox();assert.ok(actions.y+actions.height<=720,'Export actions remain visible on a laptop');
        await page.setViewportSize({width:1440,height:1100});await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        if(process.env.COMPOSE_PROOF_DIR)await page.screenshot({path:process.env.COMPOSE_PROOF_DIR+'/post-set-preview.png'});
        const downloadPromise=page.waitForEvent('download');await page.locator('#ep-layout-pack').click();const download=await downloadPromise;
        const zip=readFileSync(await download.path());
        for(const name of ['ig-feed.png','square.png','story.png','youtube.png','x-landscape.png','platform-captions.txt','placements.json'])assert.ok(zip.includes(Buffer.from(name)),'Export includes '+name);
        assert.ok(download.suggestedFilename().endsWith('-social-set.zip'));
        await page.locator('#ep-layout-channel').selectOption('X');await page.locator('#ep-layout-png:enabled').waitFor();assert.equal(await page.locator('#ep-layout-caption').inputValue(),'A revised X caption.');
        await page.locator('#ep-layout-channel').selectOption('Facebook');await page.locator('#ep-layout-png:enabled').waitFor();
        await page.locator('#ep-layout-format').selectOption('story');await page.locator('#ep-layout-png:enabled').waitFor();await page.locator('#ep-layout-apply').click();assert.equal(await dialog.isVisible(),true,'Story graphics cannot silently become feed posts');
        await page.locator('#ep-layout-format').selectOption('square');await page.locator('#ep-layout-png:enabled').waitFor();await page.locator('#ep-layout-apply').click();
        await dialog.waitFor({state:'hidden'});assert.equal(await page.locator('#compose-copy').inputValue(),'Facebook: Explore your next step with EP. Talk to our team.');
        assert.equal(await page.locator('#compose-image').isVisible(),true);
        assert.equal(writes.length,1,'Applying and exporting never save or publish posts');
    });
});

test('a changed brief cannot apply a stale generated post',async()=>{
    await withStudio(async(page)=>{
        await page.locator('#campaign-name').fill('Draft');await page.locator('#campaign-brief').fill('Explain study planning.');
        await page.locator('#generate-campaign').click();await page.locator('#ep-layout-png:enabled').waitFor();
        await page.locator('#ep-layout-close').click();await page.locator('#campaign-brief').fill('A different request.');
        // Reopen the same draft only to exercise its stale-context guard.
        await page.evaluate(()=>document.querySelector('#ep-layout-dialog').showModal());
        await page.locator('#ep-layout-channel').selectOption('Facebook');await page.locator('#ep-layout-png:enabled').waitFor();await page.locator('#ep-layout-apply').click();
        await page.getByText('The brief, platform selection, references or source photo changed. Generate a fresh set before applying.',{exact:true}).waitFor();
        assert.equal(await page.locator('#compose-copy').inputValue(),'');
    });
});
