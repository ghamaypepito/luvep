const epLayoutFormats = [
    {id:'ig-feed',title:'Portrait feed',width:1080,height:1350,channels:['Instagram','Facebook','Threads']},
    {id:'square',title:'Square feed',width:1080,height:1080,channels:['Instagram','Facebook','Threads']},
    {id:'story',title:'Story',width:1080,height:1920,channels:['Instagram','Facebook']},
    {id:'linkedin',title:'Landscape feed',width:1200,height:628,channels:['LinkedIn','Facebook']},
    {id:'linkedin-square',title:'Square feed',width:1200,height:1200,channels:['LinkedIn']},
    {id:'pin',title:'Pinterest pin',width:1000,height:1500,channels:['Pinterest']},
    {id:'shorts',title:'Vertical cover · static image',width:1080,height:1920,channels:['TikTok','YouTube']},
    {id:'youtube',title:'Video thumbnail · static image',width:1280,height:720,channels:['YouTube']},
    {id:'x-landscape',title:'Landscape feed',width:1200,height:675,channels:['X']}
];
const epCaptionLimits = {X:280,Threads:500,Pinterest:500};
const epLayoutPhotos = {compose:'',review:''};
let epLayoutDraft=null, epLayoutRender=0, epLayoutBusy=false, epAppliedComposeImage='', epAppliedReviewImage='';
const epLayoutDialog=document.createElement('dialog');
epLayoutDialog.id='ep-layout-dialog';epLayoutDialog.setAttribute('aria-labelledby','ep-layout-title');
epLayoutDialog.innerHTML=`<div class="dialog-heading"><div><span class="studio-eyebrow">AI copy · branded graphics</span><h2 id="ep-layout-title">Your suggested post set</h2></div><button type="button" class="close" id="ep-layout-close" aria-label="Close suggested post set">×</button></div>
<p class="hint">Review the copy and each placement. These are static graphics; video platforms receive covers, not finished videos.</p><p id="ep-layout-status" role="status"></p>
<div id="ep-layout-result" hidden><div class="studio-result-grid"><section class="studio-art-preview"><label for="ep-layout-channel">Platform</label><select id="ep-layout-channel"></select><label for="ep-layout-format">Placement</label><select id="ep-layout-format"></select><canvas id="ep-layout-canvas" aria-label="Generated EP layout preview"></canvas><p id="ep-layout-dimensions" class="hint"></p></section>
<section class="studio-result-editor"><label for="ep-layout-headline">Headline</label><input id="ep-layout-headline" maxlength="90"><label for="ep-layout-support">Supporting copy</label><textarea id="ep-layout-support" rows="2" maxlength="180"></textarea><label for="ep-layout-cta">Call to action</label><input id="ep-layout-cta" maxlength="60"><label for="ep-layout-composition">Composition</label><select id="ep-layout-composition"><option value="photo-top">Photo above / left</option><option value="photo-bottom">Photo below / right</option></select><label for="ep-layout-caption">Platform caption</label><textarea id="ep-layout-caption" rows="5"></textarea><p id="ep-layout-caption-count" class="hint"></p></section></div>
<div class="caption-suggestion-actions"><button type="button" class="secondary" id="ep-layout-png">Download this PNG</button><button type="button" class="secondary" id="ep-layout-source">Download layout source (JSON)</button><button type="button" class="secondary" id="ep-layout-pack">Download all sizes & captions</button><button type="button" class="primary" id="ep-layout-apply">Use as Facebook feed draft</button></div><p class="hint">Only Luv, Ep Facebook is connected for publishing. Other platforms are export-ready drafts. Applying does not save, approve or publish anything.</p></div>`;
document.body.append(epLayoutDialog);
function epCurrentPhoto(context){
    if(context==='compose') return composeMediaType==='image' ? (composeMediaUrl===epAppliedComposeImage ? epLayoutPhotos.compose : composeMediaUrl) : '';
    if(!uploaded)return '';const src=$('#post-image').src;
    return src===epAppliedReviewImage?epLayoutPhotos.review:src===epAppliedComposeImage?epLayoutPhotos.compose:src;
}
function epLayoutInputs(context){return {campaign:context==='compose'?$('#campaign-name').value.trim():$('#post-title').textContent.trim(),service:$('#campaign-service').value,channels:[...document.querySelectorAll('[name="compose-platform"]:checked')].map(x=>x.value),tone:context==='compose'?$('#campaign-tone').value:'Professional',brief:context==='compose'?$('#campaign-brief').value.trim():$('#ai-notes').value.trim(),references:context==='compose'?epReferenceSnapshot():[]};}
function epLayoutDownload(blob,name){const url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=name;a.click();setTimeout(()=>URL.revokeObjectURL(url),60000);}
function epLayoutFilename(){return (epLayoutDraft.inputs.campaign.replace(/[^a-z0-9-]+/gi,'-').slice(0,70)||'ep-design');}
function epAvailableFormats(channel){return epLayoutFormats.filter(format=>format.channels.includes(channel));}
function epResultValid(){return !!epLayoutDraft?.design?.captions && ['headline','supporting_copy','cta'].every(key=>epLayoutDraft.design[key].trim()) && epLayoutDraft.inputs.channels.every(channel=>{const copy=epLayoutDraft.design.captions[channel];return typeof copy==='string'&&copy.trim()&&[...copy].length<=(epCaptionLimits[channel]||2000);});}
function epResultButtons(disabled){
    ['#ep-layout-apply','#ep-layout-png','#ep-layout-source','#ep-layout-pack'].forEach(id=>$(id).disabled=disabled||epLayoutBusy||!!epLayoutDraft?.rendering||!epResultValid());
    $('#ep-layout-apply').disabled ||= !epLayoutDraft?.inputs.channels.includes('Facebook');
}
function epSelectResultChannel(){
    const channel=$('#ep-layout-channel').value,select=$('#ep-layout-format');select.replaceChildren();
    for(const format of epAvailableFormats(channel)){const option=document.createElement('option');option.value=format.id;option.textContent=`${format.title} · ${format.width} × ${format.height}`;select.append(option);}
    $('#ep-layout-caption').value=epLayoutDraft.design.captions[channel];$('#ep-layout-caption').maxLength=epCaptionLimits[channel]||2000;epCaptionCount();epRenderCurrentLayout();
}
function epCaptionCount(){const channel=$('#ep-layout-channel').value;$('#ep-layout-caption-count').textContent=`${[...$('#ep-layout-caption').value].length} / ${epCaptionLimits[channel]||2000} characters · ${channel}`;}
async function epRenderCurrentLayout(){
    if(!epLayoutDraft)return;const ticket=++epLayoutRender;epLayoutDraft.rendering=true;epResultButtons(true);
    const format=epLayoutFormats.find(f=>f.id===$('#ep-layout-format').value),offscreen=document.createElement('canvas');
    try{
        await drawEpLayout(offscreen,epLayoutDraft.photo,epLayoutDraft.design,format);
        const blob=await new Promise(resolve=>offscreen.toBlob(resolve,'image/png'));
        if(ticket!==epLayoutRender)return;if(!blob)throw Error('Could not render the layout.');
        const canvas=$('#ep-layout-canvas');canvas.width=offscreen.width;canvas.height=offscreen.height;canvas.getContext('2d').drawImage(offscreen,0,0);
        epLayoutDraft.png=blob;epLayoutDraft.format=format;epLayoutDraft.rendering=false;$('#ep-layout-dimensions').textContent=`${format.width} × ${format.height} px · PNG`;
        $('#ep-layout-status').textContent='Draft ready. Edit the artwork copy and each platform caption before using it.';epResultButtons(false);
    }catch(error){if(ticket===epLayoutRender)$('#ep-layout-status').textContent=error.message;}
}
async function epGenerateLayout(context){
    if(epLayoutBusy)return;
    const inputs=epLayoutInputs(context),photo=epCurrentPhoto(context),references=context==='compose'?epBriefCapture():[];
    if(!inputs.brief||!inputs.campaign||!inputs.channels.length){toast('Add a campaign name, a brief and at least one platform.');return;}
    const csrf=document.querySelector('meta[name="csrf-token"]')?.content;if(!csrf){toast('Use the signed-in Luvep workspace for generation.');return;}
    epLayoutBusy=true;epLayoutDraft=null;epLayoutRender++;$('#ep-layout-result').hidden=true;$('#ep-layout-status').textContent='Preparing your suggested graphics and platform captions…';epLayoutDialog.showModal();
    ['#generate','#generate-campaign'].forEach(id=>$(id).disabled=true);
    const abort=new AbortController(),timer=setTimeout(()=>abort.abort(),45000);
    try{
        const request=epBriefRequest(inputs,references);
        const response=await fetch('/social/workspace-test/ai/layout',{method:'POST',credentials:'same-origin',signal:abort.signal,headers:{...request.headers,Accept:'application/json','X-CSRF-TOKEN':csrf},body:request.body}),data=await response.json().catch(()=>({}));
        if(!response.ok)throw Error(response.status===419?'Your session expired. Refresh and sign in again.':response.status===429?'Too many requests. Wait a minute before trying again.':data.message||'The post set could not be generated.');
        epLayoutDraft={design:data.design,inputs,photo,context};
        if(!epResultValid())throw Error('The generated set is incomplete. Please try again.');
        $('#ep-layout-headline').value=data.design.headline;$('#ep-layout-support').value=data.design.supporting_copy;$('#ep-layout-cta').value=data.design.cta;$('#ep-layout-composition').value=data.design.layout;
        const select=$('#ep-layout-channel');select.replaceChildren();for(const channel of inputs.channels){const option=document.createElement('option');option.value=channel;option.textContent=channel;select.append(option);}
        $('#ep-layout-result').hidden=false;epSelectResultChannel();
    }catch(error){epLayoutDraft=null;$('#ep-layout-status').textContent=error.name==='AbortError'?'Generation took too long. Please try again.':error.message;}
    finally{clearTimeout(timer);epLayoutBusy=false;['#generate','#generate-campaign'].forEach(id=>$(id).disabled=false);epResultButtons(false);}
}
$('#ep-layout-close').onclick=()=>epLayoutDialog.close();$('#ep-layout-channel').onchange=epSelectResultChannel;$('#ep-layout-format').onchange=epRenderCurrentLayout;
for(const [id,key] of [['#ep-layout-headline','headline'],['#ep-layout-support','supporting_copy'],['#ep-layout-cta','cta'],['#ep-layout-composition','layout']]) $(id).addEventListener('input',()=>{epLayoutDraft.design[key]=$(id).value;epRenderCurrentLayout();});
$('#ep-layout-caption').oninput=()=>{epLayoutDraft.design.captions[$('#ep-layout-channel').value]=$('#ep-layout-caption').value;epCaptionCount();epResultButtons(false);};
$('#ep-layout-png').onclick=()=>{if(epLayoutDraft?.png)epLayoutDownload(epLayoutDraft.png,epLayoutFilename()+'-'+$('#ep-layout-format').value+'.png');};
$('#ep-layout-source').onclick=async()=>{
    if(!epResultValid())return;
    try{
        let photoData='';
        if(epLayoutDraft.photo){const image=await epLoadImage(epLayoutDraft.photo),photo=document.createElement('canvas'),scale=Math.min(1,2048/Math.max(image.naturalWidth,image.naturalHeight));photo.width=Math.round(image.naturalWidth*scale);photo.height=Math.round(image.naturalHeight*scale);photo.getContext('2d').drawImage(image,0,0,photo.width,photo.height);photoData=photo.toDataURL('image/png');}
        epLayoutDownload(new Blob([JSON.stringify({schema:'ep-layout-v1',design:epLayoutDraft.design,format:epLayoutDraft.format,font:'Urbanist',colour:'#214f44',photo_data_url:photoData},null,2)],{type:'application/json'}),epLayoutFilename()+'.json');
    }catch{ $('#ep-layout-status').textContent='The editable source could not be exported. Please try again.'; }
};
$('#ep-layout-pack').onclick=async()=>{
    if(!epResultValid()||epLayoutBusy)return;
    epLayoutBusy=true;epResultButtons(true);
    const draft=epLayoutDraft,design=JSON.parse(JSON.stringify(draft.design)),formats=epLayoutFormats.filter(format=>format.channels.some(channel=>draft.inputs.channels.includes(channel)));
    try{
        const files=[];let total=0;
        for(const format of formats){$('#ep-layout-status').textContent='Preparing '+format.title+'…';const canvas=document.createElement('canvas');await drawEpLayout(canvas,draft.photo,design,format);const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/png'));if(!blob)throw Error('An artwork size could not be exported.');const data=new Uint8Array(await blob.arrayBuffer());total+=data.length;if(total>120*1024*1024)throw Error('This set exceeds the 120MB export limit. Export individual sizes instead.');files.push({name:format.id+'.png',data});}
        files.push({name:'platform-captions.txt',data:new TextEncoder().encode(draft.inputs.channels.map(channel=>channel+'\n'+design.captions[channel]).join('\n\n'))});
        files.push({name:'placements.json',data:new TextEncoder().encode(JSON.stringify({formats,design,kind:'static graphics',status:'draft requiring review'},null,2))});
        epLayoutDownload(await zipFile(files),epLayoutFilename()+'-social-set.zip');$('#ep-layout-status').textContent='Graphics and platform captions exported. Nothing has been published.';
    }catch(error){$('#ep-layout-status').textContent=error.message;}
    finally{epLayoutBusy=false;epResultButtons(false);}
};
$('#ep-layout-apply').onclick=()=>{
    if(epLayoutBusy||epLayoutDraft?.rendering||!epResultValid()||!epLayoutDraft?.png||!epLayoutDraft.inputs.channels.includes('Facebook'))return;
    const draft=epLayoutDraft;
    if(JSON.stringify(epLayoutInputs(draft.context))!==JSON.stringify(draft.inputs)||epCurrentPhoto(draft.context)!==draft.photo){$('#ep-layout-status').textContent='The brief, platform selection, references or source photo changed. Generate a fresh set before applying.';return;}
    if(draft.format.id==='story'||!draft.format.channels.includes('Facebook')){$('#ep-layout-status').textContent='Select Facebook and a feed placement before applying. Story graphics are available for download.';return;}
    const imageUrl=URL.createObjectURL(draft.png),copy=draft.design.captions.Facebook;
    if(draft.context==='compose'){
        epLayoutPhotos.compose=draft.photo;epAppliedComposeImage=imageUrl;$('.mini-art').querySelectorAll('.local-video').forEach(node=>node.remove());$('#compose-image').src=imageUrl;$('#compose-image').alt='Generated EP branded layout';$('#compose-image').hidden=false;$('#compose-image').style.height='auto';$('#compose-art-copy').hidden=true;$('.mini-art').classList.add('uploaded');composeMediaUrl=imageUrl;composeMediaType='image';$('#compose-copy').value=copy;composePreview();
    }else{
        epLayoutPhotos.review=draft.photo;epAppliedReviewImage=imageUrl;$('#artwork').querySelectorAll('.local-video').forEach(node=>node.remove());$('#artwork').classList.remove('has-video');$('#post-image').src=imageUrl;$('#post-image').alt='Generated revised EP branded layout';$('#artwork').classList.add('uploaded');uploaded=true;$('#caption').value=copy;$('#change-note').value='Prepared a branded layout and revised copy.';dirty();
    }
    libraryUploads.unshift({type:'image',name:draft.inputs.campaign+' · '+draft.format.title,campaign:draft.inputs.campaign,format:'Generated layout · '+draft.format.width+' × '+draft.format.height,src:imageUrl});
    epLayoutDialog.close();toast('Facebook artwork and caption applied. Save and submit the draft for review when ready.');
};
for(const [id,context,label] of [['#generate-campaign','compose','Generate graphics & platform captions'],['#generate','review','Generate revised graphics & captions']]){
    const button=$(id);button.disabled=false;button.textContent=label;button.title='Uses the saved AI connection; creates drafts for review';button.onclick=()=>epGenerateLayout(context);
    const note=button.nextElementSibling;if(note?.textContent.startsWith('AI artwork needs'))note.textContent='Creates branded graphics from your brief. An optional source photo stays in this browser. Attached reference files are sent to the saved AI provider.';
}
const epDraftCount=$('#campaign-variants');if(epDraftCount){const option=document.createElement('option');option.value='1 variant';option.textContent='One coordinated post set';epDraftCount.replaceChildren(option);epDraftCount.disabled=true;document.querySelector('label[for="campaign-variants"]').textContent='Output';}
// New Compose visits start with the brief; opening saved drafts keeps their existing data.
$('[data-mode="campaign"]').click();
routes.compose=['Compose studio','One brief, suggested graphics and captions for every selected platform.'];
if(!$('#compose-view').hidden){$('#page-title').textContent=routes.compose[0];$('#page-subtitle').textContent=routes.compose[1];}
