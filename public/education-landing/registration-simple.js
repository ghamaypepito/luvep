(() => {
  'use strict';
  const root=document.getElementById('ep-education'),q=s=>root.querySelector(s),form=q('#ep-form'),error=q('#ep-error'),next=q('#ep-next');
  const visits=JSON.parse(document.getElementById('education-registration-visits').textContent);
  let busy=false,submitted=false,recognitionTimer,recognitionController;
  const selectedVisit=()=>visits.find(v=>String(v.id)===q('#ep-visit').value);
  function conditionalFields(){
    const same=q('#ep-whatsapp-same').checked,otherCountry=q('#ep-country').value==='__other__',otherEvent=q('#ep-visit').value==='__other__';
    q('#ep-whatsapp-label').hidden=same;q('#ep-whatsapp').disabled=same;q('#ep-whatsapp').required=!same;
    q('#ep-other-country-label').hidden=!otherCountry;q('#ep-other-country').disabled=!otherCountry;q('#ep-other-country').required=otherCountry;
    q('#ep-other-event-label').hidden=!otherEvent;q('#ep-event-name').disabled=!otherEvent;q('#ep-event-name').required=otherEvent;
    const visit=selectedVisit(),summary=q('#ep-managed-summary');summary.hidden=!visit&&!otherEvent;
    summary.textContent=visit ? visit.summary+' · '+(visit.location||'Venue: To Be Announced') : '7:00 PM Philippine time (Asia/Manila) · Your Education team will confirm the event details.';
  }
  for(const id of ['#ep-whatsapp-same','#ep-country','#ep-visit'])q(id).addEventListener('change',conditionalFields);
  function render(focus=false){
    q('#ep-registration-content').hidden=submitted;q('#ep-success').hidden=!submitted;q('.ep-actions').hidden=submitted;
    q('#ep-registration-fields').disabled=busy;next.disabled=busy;next.textContent=busy?'Sending…':'Register →';
    if(focus){const h=q(submitted?'#ep-success h2':'#ep-registration-content h2');h.tabIndex=-1;h.focus({preventScroll:true});h.scrollIntoView({behavior:'auto',block:'start'});}
  }
  async function post(url,payload){
    const response=await fetch(url,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify(payload)});
    let data={};try{data=await response.json();}catch{}
    if(!response.ok)throw new Error(response.status===419?'Your page has expired. Please refresh and try again.':response.status===429?'Please wait a few minutes before trying again.':Object.values(data.errors||{}).flat().join(' ')||'We could not save your details. Please try again or contact the Education team.');
    return data;
  }
  async function recognize(){
    recognitionController?.abort();q('#ep-returning-rsvp').hidden=true;
    const name=q('#ep-full-name').value.trim().replace(/\s+/g,' '),email=q('#ep-email').value.trim();
    if(!name||!email||!q('#ep-email').validity.valid)return;
    const snapshot=name+'\n'+email;recognitionController=new AbortController();
    try{const response=await fetch('/education/rsvp/recognize',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({full_name:name,email}),signal:recognitionController.signal});if(!response.ok)return;const result=await response.json();if(result.recognized&&snapshot===q('#ep-full-name').value.trim().replace(/\s+/g,' ')+'\n'+q('#ep-email').value.trim()){q('#ep-returning-rsvp').textContent='Welcome back, '+name.split(' ')[0]+'. Your verified RSVP can be linked to your journey.';q('#ep-returning-rsvp').hidden=false;}}catch{}
  }
  for(const id of ['#ep-full-name','#ep-email'])q(id).addEventListener('input',()=>{q('#ep-returning-rsvp').hidden=true;clearTimeout(recognitionTimer);recognitionTimer=setTimeout(recognize,500);});
  form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy||submitted)return;error.hidden=true;
    if(!form.reportValidity())return;
    const payload=Object.fromEntries(new FormData(form));payload.consent=q('#ep-consent').checked;payload.whatsapp_same_as_phone=q('#ep-whatsapp-same').checked;
    if(payload.country==='__other__')payload.country=q('#ep-other-country').value.trim();delete payload.other_country;
    if(payload.visit_id==='__other__')delete payload.visit_id;
    busy=true;render();
    try{const result=await post(form.action,payload);submitted=true;q('#ep-greeting').textContent=payload.full_name.trim().split(/\s+/)[0];q('#ep-receipt-session').textContent=result.event_summary;render(true);}
    catch(failure){error.textContent=failure.message;error.hidden=false;error.scrollIntoView({behavior:'auto',block:'center'});}
    finally{busy=false;render();}
  });
  function openScreen(name){q('#ep-landing').hidden=true;q('#ep-public-contact').hidden=name!=='contact';q('#ep-public-next').hidden=name!=='next';const h=q('#ep-public-'+name+' h2');h.tabIndex=-1;h.focus({preventScroll:true});h.scrollIntoView({behavior:'auto',block:'start'});}
  root.querySelectorAll('[data-screen-open]').forEach(button=>button.addEventListener('click',()=>openScreen(button.dataset.screenOpen)));
  root.querySelectorAll('[data-return]').forEach(button=>button.addEventListener('click',()=>{q('#ep-public-contact').hidden=true;q('#ep-public-next').hidden=true;q('#ep-landing').hidden=false;render(true);}));
  q('#ep-save-questions').addEventListener('click',async event=>{
    const button=event.currentTarget,questions=q('#ep-questions').value.trim(),status=q('#ep-prepare-status');
    if(!questions){q('#ep-questions').focus();status.textContent='Please enter a question for your adviser.';status.hidden=false;return;}
    button.disabled=true;button.textContent='Sending…';
    try{await post('/education/register/next-step',{submission_id:q('#ep-submission-id').value,questions});status.textContent='Your questions have been saved for the Education team. Your adviser will follow up.';button.textContent='Update my questions →';}
    catch(failure){status.textContent=failure.message;button.textContent='Try again →';}finally{button.disabled=false;status.hidden=false;}
  });
  conditionalFields();render();if(location.hash==='#contact')openScreen('contact');
})();
