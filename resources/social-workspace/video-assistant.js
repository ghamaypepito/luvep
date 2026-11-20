let epVideoRun=null,epVideoBlob=null,epVideoUrl='',epVideoKey='';
const epVideoOutputLabel=el('label','','Output');epVideoOutputLabel.htmlFor='ep-layout-output';
const epVideoOutput=el('select');epVideoOutput.id='ep-layout-output';
for(const [value,text] of [['graphic','Graphic'],['video','Animated video']]){const option=el('option','',text);option.value=value;epVideoOutput.append(option);}
const epVideoPreview=$('.studio-art-preview'),epVideoCanvas=$('#ep-layout-canvas');
epVideoPreview.insertBefore(epVideoOutputLabel,document.querySelector('label[for="ep-layout-format"]'));epVideoPreview.insertBefore(epVideoOutput,document.querySelector('label[for="ep-layout-format"]'));
const epVideoControls=el('div','studio-video-controls');epVideoControls.hidden=true;
epVideoControls.innerHTML=`<label for="ep-video-placement">Video placement</label><select id="ep-video-placement"></select><label for="ep-video-duration">Duration</label><select id="ep-video-duration"><option value="15">15 seconds</option><option value="30">30 seconds</option></select><div class="studio-video-actions"><button type="button" class="primary" id="ep-video-generate">Generate MP4 video</button><button type="button" class="secondary" id="ep-video-cancel" hidden>Cancel generation</button></div><progress id="ep-video-progress" max="100" value="0" aria-label="Video generation progress" hidden></progress><p class="hint" id="ep-video-help">Three animated scenes · 1080 × 1920 · silent MP4. Keep this tab visible while it renders. Your photo stays in this browser.</p>`;
epVideoPreview.insertBefore(epVideoControls,epVideoCanvas);
const epVideoPlayer=el('video');epVideoPlayer.id='ep-video-preview';epVideoPlayer.controls=true;epVideoPlayer.playsInline=true;epVideoPlayer.preload='metadata';epVideoPlayer.hidden=true;epVideoPlayer.setAttribute('aria-label','Generated animated video');epVideoPreview.insertBefore(epVideoPlayer,epVideoCanvas);
const epVideoDownload=el('button','secondary','Download MP4');epVideoDownload.type='button';epVideoDownload.id='ep-video-download';epVideoDownload.disabled=true;epVideoDownload.hidden=true;
epLayoutDialog.querySelector('.caption-suggestion-actions').prepend(epVideoDownload);
function epVideoFingerprint(){return epLayoutDraft?JSON.stringify([epLayoutDraft.photo,epLayoutDraft.design.headline,epLayoutDraft.design.supporting_copy,epLayoutDraft.design.cta,epLayoutDraft.design.layout,$('#ep-video-duration').value]):'';}
function epClearVideo(){
    epVideoPlayer.pause();epVideoPlayer.removeAttribute('src');epVideoPlayer.load();epVideoPlayer.hidden=true;
    if(epVideoUrl)URL.revokeObjectURL(epVideoUrl);epVideoUrl='';epVideoBlob=null;epVideoKey='';epVideoDownload.disabled=true;
}
function epVideoUnlock(run){if(!run?.fields)return;for(const [field,disabled] of run.fields)field.disabled=disabled;run.fields=null;}
function epResetVideo(){
    const run=epVideoRun;epVideoRun=null;run?.controller.abort();epVideoUnlock(run);epClearVideo();
    epVideoOutput.value='graphic';epLayoutDialog.dataset.output='graphic';epVideoControls.hidden=true;epVideoDownload.hidden=true;epVideoCanvas.hidden=false;
    $('#ep-video-cancel').hidden=true;$('#ep-video-progress').hidden=true;$('#ep-video-progress').value=0;
}
function epUpdateVideoControls(){
    if(!epLayoutDraft)return;
    if(epVideoBlob&&epVideoKey!==epVideoFingerprint())epClearVideo();
    const supported=epVideoPlacements($('#ep-layout-channel').value).length>0;
    $('#ep-video-generate').disabled=!!epVideoRun||epLayoutBusy||!!epLayoutDraft.rendering||!epResultValid()||!supported||!epVideoMimeType();
    epVideoDownload.disabled=!epVideoBlob||!!epVideoRun;
    if(epVideoOutput.value==='video'){
        epVideoCanvas.hidden=!!epVideoBlob;
        $('#ep-layout-dimensions').textContent='1080 × 1920 px · 9:16 · '+$('#ep-video-duration').value+' seconds · silent MP4';
        $('#ep-video-help').textContent=!supported?'Choose Facebook, Instagram, TikTok or YouTube for vertical video.':!epVideoMimeType()?'MP4 export needs an up-to-date Chrome or Safari. Your graphic exports remain available.':'Three animated scenes · silent MP4. Keep this tab visible while it renders. Your photo stays in this browser.';
    }
    if(epVideoRun)for(const id of ['#ep-layout-apply','#ep-layout-png','#ep-layout-source','#ep-layout-pack'])$(id).disabled=true;
}
function epSelectVideoOutput(){
    const video=epVideoOutput.value==='video';epLayoutDialog.dataset.output=video?'video':'graphic';epVideoControls.hidden=!video;epVideoDownload.hidden=!video;
    epVideoPlayer.hidden=!video||!epVideoBlob;epVideoCanvas.hidden=video&&!!epVideoBlob;
    const footer=epLayoutDialog.querySelector('#ep-layout-result > p.hint');
    footer.textContent=video?'Video drafts are download-only. Upload the MP4 in New social post to review and schedule it. No post is saved or published by generation.':'Only Luv, Ep Facebook is connected for publishing. Other platforms are export-ready drafts. Applying does not save, approve or publish anything.';
    if(!video&&epLayoutDraft?.format)$('#ep-layout-dimensions').textContent=`${epLayoutDraft.format.width} × ${epLayoutDraft.format.height} px · PNG`;
    epVideoChannel();
}
function epVideoChannel(){
    const select=$('#ep-video-placement');select.replaceChildren();
    for(const placement of epVideoPlacements($('#ep-layout-channel').value)){const option=el('option','',placement);option.value=placement;select.append(option);}
    epUpdateVideoControls();
}
epVideoOutput.onchange=epSelectVideoOutput;$('#ep-layout-channel').addEventListener('change',epVideoChannel);$('#ep-video-duration').onchange=epUpdateVideoControls;
$('#ep-video-generate').onclick=async()=>{
    if(epVideoRun||!epLayoutDraft||!epResultValid()||epLayoutDraft.rendering||epLayoutBusy)return;
    const draft=epLayoutDraft;
    if(!epVideoPlacements($('#ep-layout-channel').value).length)return;
    if(JSON.stringify(epLayoutInputs(draft.context))!==JSON.stringify(draft.inputs)||epCurrentPhoto(draft.context)!==draft.photo){$('#ep-layout-status').textContent='The brief, platforms or source photo changed. Generate a fresh draft before creating its video.';return;}
    if(document.hidden){$('#ep-layout-status').textContent='Keep this tab visible while generating video.';return;}
    epClearVideo();
    const run={controller:new AbortController(),fields:new Map([...epLayoutDialog.querySelectorAll('input,textarea,select')].map(field=>[field,field.disabled]))};epVideoRun=run;
    for(const field of run.fields.keys())field.disabled=true;
    const key=epVideoFingerprint(),duration=Number($('#ep-video-duration').value),design=JSON.parse(JSON.stringify(draft.design));
    $('#ep-video-progress').hidden=false;$('#ep-video-progress').value=0;$('#ep-video-cancel').hidden=false;$('#ep-layout-status').textContent='Rendering your '+duration+' second video…';epUpdateVideoControls();
    try{
        const blob=await epCreateBrandedVideo({photo:draft.photo,design,duration,signal:run.controller.signal,onProgress:percent=>{if(epVideoRun===run)$('#ep-video-progress').value=percent;}});
        if(epVideoRun!==run||run.controller.signal.aborted)return;
        epVideoBlob=blob;epVideoKey=key;epVideoUrl=URL.createObjectURL(blob);epVideoPlayer.src=epVideoUrl;epVideoPlayer.hidden=false;epVideoCanvas.hidden=true;
        $('#ep-layout-status').textContent='Video ready. Play the preview, then download the MP4. Nothing has been saved or published.';
    }catch(error){if(epVideoRun===run)$('#ep-layout-status').textContent=error.name==='AbortError'?(run.cancelMessage||'Video generation cancelled. Your draft is unchanged.'):'Video could not be generated. '+error.message;}
    finally{epVideoUnlock(run);if(epVideoRun===run){epVideoRun=null;$('#ep-video-cancel').hidden=true;epResultButtons(false);epUpdateVideoControls();}}
};
$('#ep-video-cancel').onclick=()=>epVideoRun?.controller.abort();
epVideoDownload.onclick=()=>{if(!epVideoBlob||epVideoKey!==epVideoFingerprint())return;const placement=($('#ep-video-placement').value||'vertical').toLowerCase().replaceAll(' ','-');epLayoutDownload(epVideoBlob,epLayoutFilename()+'-'+placement+'.mp4');};
epLayoutDialog.addEventListener('close',epResetVideo);epLayoutDialog.addEventListener('cancel',epResetVideo);$('#ep-layout-close').addEventListener('click',epResetVideo);
document.addEventListener('visibilitychange',()=>{if(document.hidden&&epVideoRun){epVideoRun.cancelMessage='Generation stopped because the tab was hidden. Keep it visible and try again.';epVideoRun.controller.abort();}});
window.addEventListener('pagehide',epResetVideo);
const epComposeOutput=$('#campaign-variants');epComposeOutput.disabled=false;epComposeOutput.replaceChildren();
for(const [value,text] of [['graphic','Graphics & captions'],['video','Animated video · Reels / Stories / Shorts']]){const option=el('option','',text);option.value=value;epComposeOutput.append(option);}
epComposeOutput.onchange=()=>{$('#generate-campaign').textContent=epComposeOutput.value==='video'?'Prepare video draft & captions':'Generate graphics & platform captions';};
$('#generate-campaign').onclick=async()=>{
    const video=epComposeOutput.value==='video';
    if(video&&![...document.querySelectorAll('[name="compose-platform"]:checked')].some(field=>epVideoPlacements(field.value).length)){toast('Select Facebook, Instagram, TikTok or YouTube for vertical video.');return;}
    await epGenerateLayout('compose');
    if(!epLayoutDraft)return;
    if(video&&!epVideoPlacements($('#ep-layout-channel').value).length){$('#ep-layout-channel').value=epLayoutDraft.inputs.channels.find(channel=>epVideoPlacements(channel).length);epSelectResultChannel();}
    epVideoChannel();epVideoOutput.value=video?'video':'graphic';epSelectVideoOutput();
};
