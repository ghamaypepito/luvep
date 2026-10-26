let captionBusy=false,captionDraftContext=null;
function epCaptionInputs(){return {campaign:$('#campaign-name').value.trim(),service:$('#campaign-service').value,channels:[...document.querySelectorAll('[name="compose-platform"]:checked')].map(x=>x.value),tone:$('#caption-tone').value,brief:$('#campaign-fields').hidden?'':$('#campaign-brief').value.trim(),references:epReferenceSnapshot()}}
async function draftCaption(){
    if(captionBusy)return;
    const campaign=$('#campaign-name').value.trim();
    const channels=[...document.querySelectorAll('[name="compose-platform"]:checked')].map(x=>x.value);
    if(!campaign){toast('Add a campaign name first.');return}
    if(!channels.length){toast('Choose at least one channel.');return}
    $('#caption-suggestions').hidden=false;
    $('#caption-context').textContent=`Campaign: ${campaign} \xb7 ${$('#campaign-service').value} \xb7 ${channels.join(', ')}`;
    let status=$('#caption-request-status');
    if(!status){status=document.createElement('p');status.id='caption-request-status';status.className='hint';status.setAttribute('role','status');$('#caption-suggestions').append(status)}
    const csrf=document.querySelector('meta[name="csrf-token"]')?.content;
    if(!csrf){status.textContent='Live caption generation is available in the signed-in Luvep workspace.';return}
    const inputs=epCaptionInputs(),references=epBriefCapture();captionDraftContext=null;
    captionBusy=true;$('#apply-caption').disabled=true;
    ['#generate-caption','#retry-caption','#caption-tone'].forEach(id=>$(id).disabled=true);
    status.textContent='Generating a draft with Claude\u2026';
    const abort=new AbortController(), timer=setTimeout(()=>abort.abort(),45000);
    try{
        const request=epBriefRequest(inputs,references);
        const response=await fetch('/social/workspace-test/ai/caption',{method:'POST',credentials:'same-origin',signal:abort.signal,
            headers:{...request.headers,'Accept':'application/json','X-CSRF-TOKEN':csrf},
            body:request.body});
        const data=await response.json().catch(()=>({}));
        if(!response.ok)throw Error(response.status===419?'Your session expired. Refresh and sign in again.':response.status===429?'Too many requests. Please wait a minute.':data.message||'Claude could not generate a caption. Please try again.');
        if(typeof data.caption!=='string'||!data.caption.trim())throw Error('Claude returned an empty caption. Please try again.');
        if(JSON.stringify(epCaptionInputs())!==JSON.stringify(inputs))throw Error('The campaign brief or references changed. Generate a fresh caption.');
        captionDraftContext=JSON.stringify(inputs);
        $('#caption-draft').value=data.caption;$('#apply-caption').disabled=false;
        status.textContent='Claude draft ready. Review it before applying. Nothing has been submitted or published.';
    }catch(error){status.textContent=error.name==='AbortError'?'Claude took too long. Please try again.':error.message}
    finally{clearTimeout(timer);captionBusy=false;['#generate-caption','#retry-caption','#caption-tone'].forEach(id=>$(id).disabled=false)}
}
$('#generate-caption').onclick=draftCaption;
$('#caption-tone').onchange=draftCaption;
$('#retry-caption').onclick=draftCaption;
$('#dismiss-caption').onclick=()=>{$('#caption-suggestions').hidden=true};
$('#apply-caption').onclick=()=>{
    if(captionDraftContext!==JSON.stringify(epCaptionInputs())){toast('The campaign brief or references changed. Generate a fresh caption.');return}
    const copy=$('#caption-draft').value.trim();
    if(!copy){toast('Add text before applying.');return}
    $('#compose-copy').value=copy;composePreview();
    libraryUploads.unshift({type:'copy',name:$('#campaign-name').value.trim()+' \xb7 caption draft '+(libraryUploads.length+1),campaign:$('#campaign-name').value.trim(),format:'Claude caption draft \xb7 '+$('#caption-tone').value,copy});
    $('#caption-suggestions').hidden=true;
    $('#compose-feedback').textContent='Claude caption applied to this browser draft. Review it before submitting.';
    toast('Caption applied. Nothing has been submitted, scheduled or published.');
};

// Keep disconnected artwork generation explicit; never pass off sample art as an AI result.
['#generate','#generate-campaign'].forEach(id=>{const button=$(id);if(button){button.disabled=true;button.onclick=null;button.title='Image-generation provider not connected';button.textContent='AI artwork not connected';const note=document.createElement('p');note.className='hint';note.textContent='AI artwork needs an image-generation provider. Use Upload design; Claude captions are available in New campaign.';button.after(note);}});
