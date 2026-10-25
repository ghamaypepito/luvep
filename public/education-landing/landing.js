(() => {
  'use strict';
  const root = document.getElementById('ep-education');
  const q = selector => root.querySelector(selector);
  const form = q('#ep-form');
  const next = q('#ep-next');
  const back = q('#ep-back');
  const error = q('#ep-error');
  const landing = q('#ep-landing');
  const success = q('#ep-success');
  let recognitionTimer, recognitionController;
  async function recognizeRsvp() {
    recognitionController?.abort();
    const notice=q('#ep-returning-rsvp'); notice.hidden=true;
    const first=q('#ep-first').value.trim(),last=q('#ep-last').value.trim(),email=q('#ep-email').value.trim();
    if(!first||!last||!email||!q('#ep-email').validity.valid)return;
    const snapshot=first+' '+last+'\n'+email; recognitionController=new AbortController();
    try {const response=await fetch('/education/rsvp/recognize',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({full_name:first+' '+last,email}),signal:recognitionController.signal});if(!response.ok)return;const result=await response.json();if(result.recognized&&snapshot===q('#ep-first').value.trim()+' '+q('#ep-last').value.trim()+'\n'+q('#ep-email').value.trim()){notice.textContent='Welcome back, '+first+'. Your verified RSVP can be linked to your journey.';notice.hidden=false;}}catch{}
  }
  for(const id of ['#ep-first','#ep-last','#ep-email'])q(id).addEventListener('input',()=>{q('#ep-returning-rsvp').hidden=true;clearTimeout(recognitionTimer);recognitionTimer=setTimeout(recognizeRsvp,500);});
  const visits = JSON.parse(document.getElementById('education-registration-visits')?.textContent || '[]');
  const selectedVisit = () => visits.find(v => String(v.id) === q('#ep-visit')?.value);
  function updateVisitSummary() {
    const visit = selectedVisit(), summary = q('#ep-managed-summary');
    summary.hidden = !visit;
    summary.textContent = visit ? visit.activity + ' · ' + visit.summary + (visit.location ? ' · ' + visit.location : '') : '';
  }
  function chooseSchool() {
    const school = q('#ep-school').value, other = school === '__other__', managed = !!school && !other;
    q('#ep-other-school-label').hidden = !other; q('#ep-other-school').disabled = !other; q('#ep-other-school').required = other;
    q('#ep-manual-event').hidden = !other;
    for (const field of q('#ep-manual-event').querySelectorAll('input,select,button')) field.disabled = !other;
    const select = q('#ep-visit'); select.disabled = !managed; select.required = managed; q('#ep-visit-label').hidden = !managed;
    select.replaceChildren(new Option('Choose your event', ''));
    const matches = visits.filter(v => v.school.trim().toLocaleLowerCase() === school.trim().toLocaleLowerCase());
    for (const visit of matches) select.add(new Option(visit.activity + ' · ' + visit.summary, visit.id));
    if (matches.length === 1) select.value = String(matches[0].id);
    updateVisitSummary();
  }
  if (q('#ep-visit')) {q('#ep-school').addEventListener('change', chooseSchool); q('#ep-visit').addEventListener('change', updateVisitSummary); chooseSchool();}
  let step = 0;
  let submitted = false;
  let busy = false;
  const goal = document.createElement('input');
  goal.type = 'hidden'; goal.name = 'goal'; goal.value = 'Find the right course';
  form.append(goal);

  function render(focus = false) {
    root.querySelectorAll('[data-page]').forEach(el => { el.hidden = submitted || Number(el.dataset.page) !== step; });
    root.querySelectorAll('[data-step]').forEach(el => {
      if (Number(el.dataset.step) === step) el.setAttribute('aria-current', 'step');
      else el.removeAttribute('aria-current');
    });
    back.hidden = step === 0 || submitted;
    q('.ep-actions').hidden = submitted;
    success.hidden = !submitted;
    next.textContent = busy ? 'Sending…' : step === 2 ? 'Register →' : 'Continue →';
    next.disabled = busy;
    back.disabled = busy;
    if (focus) {
      const heading = (submitted ? success : q('[data-page="' + step + '"]')).querySelector('h2');
      heading.tabIndex = -1;
      heading.focus({ preventScroll: true });
      heading.scrollIntoView({ behavior: 'auto', block: 'start' });
    }
  }

  function eventSummary() {
    const managed = selectedVisit(); if (managed) return managed.activity + ' · ' + managed.summary;
    const date = q('#ep-event-date').value;
    const formatted = date ? new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(date + 'T12:00:00')) : '';
    return [q('#ep-event-name').value.trim(), formatted, q('#ep-event-hour').value + ':' + q('#ep-event-minute').value.padStart(2, '0') + ' ' + q('#ep-event-period').value, q('#ep-event-zone').value].join(' · ');
  }

  async function post(url, payload) {
    const response = await fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
      body: JSON.stringify(payload),
    });
    let data;
    try { data = await response.json(); } catch { data = {}; }
    if (!response.ok) {
      const messages = Object.values(data.errors || {}).flat();
      const message = response.status === 419 ? 'Your page has expired. Please refresh and try again.' : response.status === 429 ? 'Please wait a few minutes before trying again.' : messages.join(' ') || 'We could not save your details. Please try again, or contact the Education team.';
      const failure = new Error(message);
      failure.fields = Object.keys(data.errors || {});
      throw failure;
    }
    return data;
  }

  async function advance() {
    if (busy || submitted) return;
    error.hidden = true;
    const active = q('[data-page="' + step + '"]');
    for (const field of active.querySelectorAll('input,select,textarea')) {
      if (!field.reportValidity()) return;
    }
    if (step < 2) { step++; render(true); return; }
    const payload = Object.fromEntries(new FormData(form));
    payload.consent = q('#ep-consent').checked;
    if (payload.school === '__other__') payload.school = q('#ep-other-school').value.trim();
    delete payload.other_school;
    busy = true; render();
    try {
      const result = await post(form.action, payload);
      submitted = true;
      q('#ep-greeting').textContent = q('#ep-first').value.trim();
      q('#ep-receipt-session').textContent = result.event_summary || eventSummary();
      render(true);
    } catch (failure) {
      if (failure.fields?.some(key => key.startsWith('event_') || ['visit_id', 'country', 'school', 'education_level', 'planning_timeline'].includes(key))) step = 1;
      error.textContent = failure.message; error.hidden = false;
      render(); error.scrollIntoView({ behavior: 'auto', block: 'center' });
    } finally { busy = false; render(); }
  }

  root.querySelectorAll('[data-goal]').forEach(button => button.addEventListener('click', () => {
    goal.value = button.dataset.goal;
    root.querySelectorAll('[data-goal]').forEach(peer => peer.setAttribute('aria-pressed', String(peer === button)));
  }));
  root.querySelectorAll('[data-period]').forEach(button => button.addEventListener('click', () => {
    q('#ep-event-period').value = button.dataset.period;
    root.querySelectorAll('[data-period]').forEach(peer => peer.setAttribute('aria-pressed', String(peer === button)));
  }));
  next.addEventListener('click', advance);
  back.addEventListener('click', () => { if (!busy) { step--; error.hidden = true; render(true); } });
  form.addEventListener('submit', event => { event.preventDefault(); advance(); });

  function openScreen(name) {
    landing.hidden = true;
    q('#ep-public-contact').hidden = name !== 'contact';
    q('#ep-public-next').hidden = name !== 'next';
    const heading = q('#ep-public-' + name + ' h2');
    heading.tabIndex = -1; heading.focus({ preventScroll: true });
    heading.scrollIntoView({ behavior: 'auto', block: 'start' });
  }
  root.querySelectorAll('[data-screen-open]').forEach(button => button.addEventListener('click', () => openScreen(button.dataset.screenOpen)));
  root.querySelectorAll('[data-return]').forEach(button => button.addEventListener('click', () => {
    q('#ep-public-contact').hidden = true; q('#ep-public-next').hidden = true; landing.hidden = false; render(true);
  }));
  q('#ep-save-questions').addEventListener('click', async event => {
    const button = event.currentTarget;
    const questions = q('#ep-questions').value.trim();
    const status = q('#ep-prepare-status');
    if (!questions) { q('#ep-questions').focus(); status.textContent = 'Please enter a question for your adviser.'; status.hidden = false; return; }
    button.disabled = true; button.textContent = 'Sending…';
    try {
      await post('/education/register/next-step', { submission_id: q('#ep-submission-id').value, questions });
      status.textContent = 'Your questions have been saved for the Education team. Your adviser will follow up.';
      button.textContent = 'Update my questions →';
    } catch (failure) { status.textContent = failure.message; button.textContent = 'Try again →'; }
    finally { button.disabled = false; status.hidden = false; }
  });
  if (location.hash === '#contact') openScreen('contact');
  render();
})();
