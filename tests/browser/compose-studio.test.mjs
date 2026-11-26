import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync, mkdirSync, copyFileSync } from 'node:fs';
import path from 'node:path';
import { chromium } from '@playwright/test';

const assets=path.resolve('resources/social-workspace');
const sample={headline:'Your next chapter starts here.',supporting_copy:'Explore study planning with EP.',cta:'Talk to our team',caption:'Start with a conversation.',layout:'photo-top'};
async function withStudio(run,{mp4Supported=true,posts=[],driveAssets=[],canPublish=true,canManageIdentity=canPublish}={}){
    const browser=await chromium.launch({headless:true,...(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{})});
    try{
        const driveWrites=[];
        const page=await browser.newPage({viewport:{width:1440,height:1100}}),writes=[],errors=[];
        if(!mp4Supported)await page.addInitScript(()=>{MediaRecorder.isTypeSupported=()=>false;});
        page.setDefaultTimeout(6000);page.on('pageerror',error=>errors.push(error.message));
        await page.route('**/*',async route=>{
            const request=route.request(),url=new URL(request.url());
            if(url.hostname!=='studio.test'){await route.abort();return;}
            if(url.pathname==='/social/workspace-test/ai/layout'){
                const data=request.postDataJSON();writes.push(data);
                await route.fulfill({json:{design:{...sample,captions:Object.fromEntries(data.channels.map(channel=>[channel,`${channel}: Explore your next step with EP. Talk to our team.`]))},draft:true}});return;
            }
            if(url.pathname==='/social/marketing/posts'){assert.equal(request.method(),'GET');await route.fulfill({json:{posts,can_approve:true,can_publish:canPublish,can_manage_identity:canManageIdentity,ghl_user_configured:true}});return;}
            if(url.pathname==='/social/ghl/accounts'){await route.fulfill({json:{publishing_enabled:true,accounts:[{selected:true,name:'Luv, Ep',platform:'facebook',active:true}]}});return;}
            if(url.pathname.startsWith('/social/drive/posts/')){driveWrites.push(request.postDataJSON());await route.fulfill({json:{asset:{id:'asset-1'}}});return;}
            if(url.pathname==='/social/drive/assets'){await route.fulfill({json:{assets:{data:driveAssets,current_page:1,last_page:1,total:driveAssets.length}}});return;}
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
        await run(page,writes,driveWrites);assert.deepEqual(errors,[]);
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

test('animated branded video exports an actual MP4 and cancellation preserves the draft',async()=>{
    await withStudio(async(page,writes)=>{
        await page.locator('#campaign-name').fill('Animated study planning');await page.locator('#campaign-brief').fill('Invite prospective students to plan their next chapter with EP.');
        await page.locator('#compose-media').setInputFiles(path.join(assets,'assets/new-zealand.png'));
        await page.locator('#campaign-variants').selectOption('video');
        await page.getByRole('button',{name:'Prepare video draft & captions',exact:true}).click();
        await page.locator('#ep-video-generate:enabled').waitFor();
        assert.equal(await page.locator('#ep-layout-output').inputValue(),'video');
        assert.deepEqual(await page.locator('#ep-video-placement option').allTextContents(),['Reel','Story']);
        assert.equal(await page.locator('#ep-layout-apply').isVisible(),false,'Video cannot enter the image-only approval flow');
        await page.locator('#ep-video-generate').click();
        await page.locator('#ep-video-cancel').waitFor();assert.equal(await page.locator('#ep-layout-headline').isDisabled(),true);
        await page.locator('#ep-video-cancel').click();await page.getByText('Video generation cancelled. Your draft is unchanged.',{exact:true}).waitFor();
        assert.equal(await page.locator('#ep-layout-headline').isDisabled(),false);
        assert.equal(await page.locator('#ep-video-download').isDisabled(),true);
        await page.locator('#ep-video-generate').click();
        await page.locator('#ep-video-download:enabled').waitFor({timeout:30000});
        await page.waitForFunction(()=>{const video=document.querySelector('#ep-video-preview');return video.videoWidth===1080&&video.videoHeight===1920&&Number.isFinite(video.duration);},{},{timeout:10000});
        const metadata=await page.locator('#ep-video-preview').evaluate(video=>({width:video.videoWidth,height:video.videoHeight,duration:video.duration}));
        assert.ok(metadata.duration>=14&&metadata.duration<=17,JSON.stringify(metadata));
        const downloadPromise=page.waitForEvent('download');await page.locator('#ep-video-download').click();const download=await downloadPromise;
        assert.ok(download.suggestedFilename().endsWith('-reel.mp4'));
        await page.locator('#ep-video-preview').evaluate(video=>video.play());
        await page.waitForFunction(()=>document.querySelector('#ep-video-preview').currentTime>.3);
        await page.locator('#ep-video-preview').evaluate(video=>video.pause());
        const file=await download.path(),bytes=readFileSync(file);assert.equal(bytes.subarray(4,8).toString(),'ftyp','A real MP4 container, not renamed WebM');
        if(process.env.COMPOSE_PROOF_DIR){copyFileSync(file,process.env.COMPOSE_PROOF_DIR+'/animated-reel-sample.mp4');await page.screenshot({path:process.env.COMPOSE_PROOF_DIR+'/animated-video-preview.png'});}
        assert.equal(writes.length,1,'Video generation makes no additional provider or publish requests');
        await page.locator('#ep-video-duration').selectOption('30');assert.equal(await page.locator('#ep-video-download').isDisabled(),true,'Changing rendered content invalidates the old video');
    });
});

test('unsupported MP4 export has a clear message and keeps graphic exports working',async()=>{
    await withStudio(async(page)=>{
        await page.locator('#campaign-name').fill('Draft');await page.locator('#campaign-brief').fill('Explain study planning.');
        await page.locator('#campaign-variants').selectOption('video');await page.locator('#generate-campaign').click();
        await page.getByText('MP4 export needs an up-to-date Chrome or Safari. Your graphic exports remain available.',{exact:true}).waitFor();
        assert.equal(await page.locator('#ep-video-generate').isDisabled(),true);
        await page.locator('#ep-layout-output').selectOption('graphic');await page.locator('#ep-layout-png:enabled').waitFor();
    },{mp4Supported:false});
});

test('workflow navigation separates queues, groups external tools and saves approved snapshots to Social Drive',async()=>{
    const posts=[['draft','Draft one'],['in_review','Review one'],['changes_requested','Revise one'],['approved','Ready one']].map(([status,campaign],i)=>({id:'post-'+i,version:2,campaign,status,caption:'Saved caption',brief:'Saved creative brief',service:'General'}));
    await withStudio(async(page,writes,driveWrites)=>{
        const nav=page.getByRole('navigation',{name:'Marketing'});
        assert.deepEqual(await nav.locator('button[data-step]').allTextContents(),['1 Brief','2 Drafts','3 Approval1','4 Revisions','5 For posting','6 Social Drive','Settings']);
        await nav.getByRole('button',{name:'2 Drafts',exact:true}).click();
        const review=page.locator('#review-view');assert.equal(await review.locator('.ready-post').count(),1);await review.getByText('Draft one',{exact:true}).waitFor();
        await nav.getByRole('button',{name:/3 Approval/}).click();await review.getByText('Review one',{exact:true}).waitFor();assert.equal(await review.getByText('Draft one',{exact:true}).count(),0);
        await nav.getByRole('button',{name:'4 Revisions',exact:true}).click();await review.getByText('Revise one',{exact:true}).waitFor();
        await nav.getByRole('button',{name:'5 For posting',exact:true}).click();await page.locator('#ready-view').getByText('Ready one',{exact:true}).waitFor();
        await page.getByRole('button',{name:'Save to Social Drive',exact:true}).click();assert.deepEqual(driveWrites,[{version:2}]);await page.getByRole('button',{name:'Saved to Social Drive',exact:true}).waitFor();
        await nav.getByRole('button',{name:'6 Social Drive',exact:true}).click();await page.getByText('Approved social asset',{exact:true}).waitFor();
        assert.equal(await page.locator('#social-drive-view').isVisible(),true);assert.equal(await page.locator('#ready-view').isVisible(),false);
        if(process.env.COMPOSE_PROOF_DIR)await page.screenshot({path:process.env.COMPOSE_PROOF_DIR+'/social-workflow-preview.png'});
        await nav.getByRole('button',{name:'Settings',exact:true}).click();await page.getByRole('heading',{name:'Settings & resources',exact:true}).waitFor();
        await nav.locator('summary').click();assert.equal(await nav.getByRole('link',{name:'GHL Social Planner ↗',exact:true}).isVisible(),true);
        await nav.getByRole('button',{name:'1 Brief',exact:true}).click();
        await page.locator('#compose-media').setInputFiles({name:'larger-reel.mp4',mimeType:'video/mp4',buffer:Buffer.alloc(11*1024*1024)});
        assert.equal(await page.locator('#compose-media-name').textContent(),'larger-reel.mp4','MP4 uploads use the 50MB limit, not the image limit');
        assert.equal(writes.length,0,'Navigation and archiving never generate or publish content');
    },{posts,driveAssets:[{id:'asset-1',name:'Approved social asset',caption:'Saved caption',mime:'image/png',source_version:2}]});
});


test('admin approvers see review actions without publishing or identity controls',async()=>{
    const posts=[{id:'review-one',version:2,campaign:'Admin approval example',status:'in_review',caption:'Saved caption',service:'General'},{id:'approved-one',version:3,campaign:'Approved example',status:'approved',caption:'Approved copy',service:'General',scheduled_at:'2099-11-22T00:00:00Z'}];
    await withStudio(async(page)=>{
        await page.getByRole('button',{name:/3 Approval/}).click();await page.getByRole('button',{name:'Inspect saved version',exact:true}).click();
        await page.getByRole('button',{name:'Approve version 2',exact:true}).waitFor();await page.getByRole('button',{name:'Request changes',exact:true}).waitFor();
        await page.getByRole('button',{name:'Close GHL details',exact:true}).click();
        await page.getByRole('button',{name:'5 For posting',exact:true}).click();assert.equal(await page.getByRole('button',{name:'Schedule in GHL',exact:true}).count(),0);
        await page.getByRole('button',{name:'Settings',exact:true}).click();assert.equal(await page.getByRole('button',{name:/GHL identity configured|Choose GHL scheduling identity/}).isVisible(),false);assert.equal(await page.getByRole('link',{name:'Digital team access ↗',exact:true}).isVisible(),false);
    },{posts,canPublish:false});
});


test('granted publisher can schedule approved content without identity settings',async()=>{
    const posts=[{id:'approved-one',version:3,campaign:'Approved example',status:'approved',caption:'Approved copy',service:'General',scheduled_at:'2099-11-22T00:00:00Z'}];
    await withStudio(async(page)=>{
        await page.getByRole('button',{name:'5 For posting',exact:true}).click();
        await page.getByRole('button',{name:'Schedule in GHL',exact:true}).waitFor();
        await page.getByRole('button',{name:'Settings',exact:true}).click();
        assert.equal(await page.getByRole('button',{name:/GHL identity configured|Choose GHL scheduling identity/}).isVisible(),false);
        assert.equal(await page.getByRole('link',{name:'Digital team access ↗',exact:true}).isVisible(),false);
    },{posts,canPublish:true,canManageIdentity:false});
});
