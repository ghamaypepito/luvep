import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
const base='/portal/education/school-mail';
const input='rounded-lg border border-gray-300 px-3 py-2';
const sampleContext = {
    first_name: 'Jamie', last_name: 'Rivera', full_name: 'Jamie Rivera', email: 'jamie.rivera@example.test',
    school: 'Example College', event_name: 'School visit', event_date: '15 October 2026',
    event_time: '10:00 AM', event_location: 'Example College auditorium',
};
const previewPolicy = `<meta http-equiv="Content-Security-Policy" content="default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">`;
const escape=v=>String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
const fill=(value,context,html=false)=>String(value||'').replace(/\{\{[ \t\r\n\f\v]*([a-z0-9_]+)[ \t\r\n\f\v]*\}\}/gi,(_,key)=>html?escape(context?.[key.toLowerCase()]??''):(context?.[key.toLowerCase()]??''));
export default function SchoolMail(props) {
    return <SchoolMailForm key={JSON.stringify(props.filters)} {...props} />;
}
function SchoolMailForm({visits=[],filters,recipients=[],templates=[],mailings=[],audienceLists=[],audienceGroups=[],recipientLists=[]}) {
    const activeList=recipientLists.find(list=>list.id===filters.recipient_list_id);
    const [visitIds,setVisits]=useState(filters.visit_ids.map(String));
    const [audience,setAudience]=useState(filters.audience);
    const [purpose,setPurpose]=useState(filters.purpose);
    const [listId,setListId]=useState(String(filters.list_id||''));
    const [folderId,setFolderId]=useState(String(filters.folder_id||''));
    const [registeredFrom,setRegisteredFrom]=useState(filters.registered_from||'');
    const [registeredTo,setRegisteredTo]=useState(filters.registered_to||'');
    const [selected,setSelected]=useState(()=>new Set(recipients.filter(row=>row.selected&&row.eligible).map(row=>row.id)));
    const [search,setSearch]=useState('');
    const [name,setName]=useState('');
    const [templateId,setTemplateId]=useState('');
    const [confirmedReview,setConfirmed]=useState(null);
    const [sending,setSending]=useState(false);
    const [errors,setErrors]=useState({});
    const [saveListId,setSaveListId]=useState(activeList?.id||null);
    const [listName,setListName]=useState(activeList?.name||'');
    const [listMode,setListMode]=useState(activeList?.mode||'smart');
    const [savingList,setSavingList]=useState(false);
    const [listErrors,setListErrors]=useState({});
    const [submissionId]=useState(()=>crypto.randomUUID());
    const dirty=JSON.stringify(visitIds)!==JSON.stringify(filters.visit_ids.map(String)) || audience!==filters.audience || purpose!==filters.purpose
        || listId!==String(filters.list_id||'') || folderId!==String(filters.folder_id||'') || registeredFrom!==(filters.registered_from||'') || registeredTo!==(filters.registered_to||'');
    const rows=useMemo(()=>recipients.filter(r=>`${r.name} ${r.email}`.toLowerCase().includes(search.toLowerCase())),[recipients,search]);
    const chosen=recipients.filter(r=>r.eligible && selected.has(r.id));
    const template=templates.find(t=>String(t.id)===templateId);
    const confirmed=Boolean(template&&confirmedReview===template.review);
    const previewSubject=template?fill(template.subject,sampleContext):'';
    const previewHtml=template?`<!doctype html>${previewPolicy}${template.shell.replace(/^<!doctype html>/i,'').replace(
        /&lt;!-- school-mail-subject --&gt;|<!-- school-mail-body -->/g,
        marker=>marker.startsWith('&lt;')?escape(previewSubject):fill(template.body,sampleContext,true))}`:'';
    const resetReview=()=>{setConfirmed(null);setSelected(new Set());};
    const toggleVisit=id=>{resetReview();setVisits(ids=>ids.includes(id)?ids.filter(v=>v!==id):[...ids,id]);};
    const toggle=id=>{setConfirmed(null);setSelected(prev=>{const next=new Set(prev);next.has(id)?next.delete(id):next.add(id);return next;});};
    const load=()=>router.get(base,{visit_ids:visitIds,audience,purpose,list_id:listId,folder_id:folderId,registered_from:registeredFrom,registered_to:registeredTo,recipient_list_id:filters.recipient_list_id,...(filters.edit_members?{edit_members:1}:{})},{preserveState:false});
    const saveList=()=>{
        if(dirty||savingList||!listName.trim()) return;
        setSavingList(true);setListErrors({});
        router.post(`${base}/recipient-lists`,{...filters,save_list_id:saveListId,list_name:listName,mode:listMode,
            ...(listMode==='custom'?{recipient_keys:chosen.map(row=>row.id)}:{}),
        },{preserveScroll:true,onError:setListErrors,onFinish:()=>setSavingList(false)});
    };
    const send=()=>{
        if (!confirmed||dirty||sending||!chosen.length||!template||!name.trim()) return;
        setSending(true);setErrors({});
        router.post(base,{...filters,name,template_id:Number(templateId),template_review:confirmedReview,recipient_keys:chosen.map(r=>r.id),submission_id:submissionId,confirmed:true},{
            preserveState:'errors',onError:e=>{setConfirmed(null);setErrors(e);},onFinish:()=>setSending(false),
        });
    };
    return <main className="mx-auto max-w-6xl space-y-6 pb-12">
        <Head title="School visit bulk email" />
        <header><a href="/portal/education/bulk-email" className="text-teal-700 underline">Back to Bulk Mail</a>
            <h1 className="mt-3 text-2xl font-bold">School visit bulk email</h1>
            <p className="mt-2 text-gray-600">Choose Education visits, review email permissions and queue one private email per eligible address. Client portal accounts are not required.</p>
        </header>
        <section className="space-y-4 rounded-xl border bg-white p-5">
            <h2 className="font-semibold">School visits</h2>
            <label className="block text-sm">My event recipient lists<select aria-label="My event recipient lists" className={`${input} mt-1 w-full`} value={filters.recipient_list_id||''} onChange={event=>router.get(base,event.target.value?{recipient_list_id:event.target.value,load_saved_list:1,purpose}:{},{preserveState:false})}>
                <option value="">Choose a saved event list</option>{recipientLists.map(list=><option key={list.id} value={list.id}>{list.name} · {list.mode==='smart'?'Smart':'Custom'}</option>)}
            </select></label>
            {activeList?.mode==='custom'&&!filters.edit_members&&<button type="button" className="text-sm text-teal-700 underline" onClick={()=>router.get(base,{recipient_list_id:activeList.id,load_saved_list:1,edit_members:1,purpose},{preserveState:false})}>Edit list members</button>}
            {filters.edit_members&&<p className="text-sm text-teal-800">Editing custom list members. Choose registrants below, then update the event list.</p>}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label className="text-sm">Saved RSVP smart list<select aria-label="Saved RSVP smart list" value={listId} className={`${input} mt-1 w-full`} onChange={event=>{
                    resetReview();setListId(event.target.value);setFolderId('');
                    const saved=audienceLists.find(list=>String(list.id)===event.target.value);
                    if(saved?.filters?.visit_id) setVisits([String(saved.filters.visit_id)]);
                }}><option value="">All matching registrations</option>{audienceLists.map(list=><option key={list.id} value={list.id}>{list.folder?.name} / {list.name}</option>)}</select></label>
                <label className="text-sm">Custom attendee group<select aria-label="Custom attendee group" value={folderId} className={`${input} mt-1 w-full`} onChange={event=>{resetReview();setFolderId(event.target.value);setListId('');}}>
                    <option value="">All attendees</option>{audienceGroups.map(group=><option key={group.id} value={group.id}>{group.name}</option>)}
                </select></label>
                <label className="text-sm">Registered from<input aria-label="School registered from" type="date" value={registeredFrom} className={`${input} mt-1 w-full`} onChange={event=>{resetReview();setRegisteredFrom(event.target.value);}} /></label>
                <label className="text-sm">Registered through<input aria-label="School registered through" type="date" min={registeredFrom} value={registeredTo} className={`${input} mt-1 w-full`} onChange={event=>{resetReview();setRegisteredTo(event.target.value);}} /></label>
            </div>
            <p className="text-sm text-gray-600">Registration dates use Asia/Manila. Saved RSVP lists and groups select matching RSVP addresses within the chosen visits.</p>
            <a href="/portal/education/rsvps#attendance" className="inline-block text-sm text-teal-700 underline">Create or edit event smart lists and custom attendee groups</a>
            <p className="text-sm text-gray-600">Select the exact visit or visits for Jose Maria College, Tagum, or another school. Different activities at the same school remain separate.</p>
            <div className="max-h-64 overflow-y-auto space-y-2">{visits.map(v=><label key={v.id} className="flex items-start gap-3">
                <input type="checkbox" checked={visitIds.includes(String(v.id))} onChange={()=>toggleVisit(String(v.id))} />
                <span>{v.school}<small className="block text-gray-500">{v.activity} · {v.summary}</small></span>
            </label>)}</div>
            <div className="flex flex-wrap gap-3">
                <label>Audience <select aria-label="Audience" className={input} value={audience} onChange={e=>{resetReview();setAudience(e.target.value);}}>
                    <option value="registered">All registered (excluding cancelled RSVPs)</option><option value="attended">Recorded attended RSVPs</option>
                </select></label>
                <label>Email purpose <select aria-label="Email purpose" className={input} value={purpose} onChange={e=>{resetReview();setPurpose(e.target.value);}}>
                    <option value="email_follow_up">School-visit follow-up</option><option value="email_newsletter">Newsletter / promotion</option>
                </select></label>
                <button className="rounded-lg bg-teal-800 px-4 py-2 text-white disabled:opacity-50" disabled={!visitIds.length||visitIds.length>20} onClick={load}>Load recipients</button>
            </div>
            <p className="text-sm text-gray-600">Attendance can be marked automatically after the event date. “Recorded attended” does not independently verify physical attendance. {purpose==='email_follow_up'?'Recorded follow-up permission is sufficient; email verification is not required.':'Newsletters require a verified RSVP email or confirmed programme email choice.'} Missing or conflicting permissions, unsubscribes and prior bounces remain excluded. Older programme registrations without a visit link require support review and are not guessed into a school group.</p>
        </section>
        {dirty&&<p role="alert" className="text-amber-800">Filters changed. Load recipients again before reviewing or sending.</p>}
        {!dirty&&<>
            <section className="space-y-3 rounded-xl border bg-white p-5">
                <h2 className="font-semibold">Recipients: {chosen.length} selected · {recipients.filter(r=>r.eligible).length} eligible · {recipients.length} unique addresses</h2>
                <input aria-label="Search recipients" placeholder="Search name or email" className={input} value={search} onChange={e=>setSearch(e.target.value)} />
                <button className="ml-3 underline" onClick={()=>{setConfirmed(null);setSelected(new Set(rows.filter(r=>r.eligible).map(r=>r.id)));}}>Select eligible matches</button>
                <button className="ml-3 underline" onClick={resetReview}>Clear selection</button>
                <p className="text-sm text-gray-600">Selection is listed below even when search hides a recipient. One email per address across the selected visits.</p>
                {chosen.length>0&&<p className="text-sm">Selected: {chosen.map(r=>`${r.name} (${r.email})`).join(', ')}</p>}
                <ul className="max-h-80 overflow-y-auto divide-y">{rows.map(r=><li key={r.id} className="py-3"><label className="flex gap-3">
                    <input type="checkbox" disabled={!r.eligible} checked={selected.has(r.id)} onChange={()=>toggle(r.id)} />
                    <span>{r.name} · {r.email}<small className="block text-gray-600">{r.schools.join(', ')} · {r.attendance}</small>
                        {!r.eligible&&<small className="block text-amber-800">{r.reason}</small>}
                        {r.conflicting_names?.map(record=><small key={`${record.source}:${record.id}`} className="block text-gray-600">{record.source==='rsvp'?'RSVP':'Programme registration'} #{record.id}: {record.name}</small>)}
                        {!!r.conflicting_names?.length&&<small className="block text-gray-600">{r.contact_numbers_match?'Recorded contact numbers match.':'Recorded contact numbers could not be matched.'}</small>}
                    </span>
                </label></li>)}</ul>
                {!rows.length&&<p>No registrations match these visits and filters. Do not select a general event as a substitute.</p>}
                <details className="rounded-lg border p-3">
                    <summary className="cursor-pointer font-semibold">Save event recipient list</summary>
                    <div className="mt-3 space-y-3">
                        <label className="block text-sm">List name<input aria-label="Event recipient list name" maxLength={100} value={listName} onChange={event=>setListName(event.target.value)} className={`${input} mt-1 w-full`} /></label>
                        <label className="block text-sm">List type<select aria-label="Event recipient list type" value={listMode} onChange={event=>setListMode(event.target.value)} className={`${input} mt-1 w-full`}>
                            <option value="smart">Smart list — matches these visits and filters</option><option value="custom">Custom list — selected registrants</option>
                        </select></label>
                        <p className="text-sm text-gray-600">These private lists include RSVPs and programme registrations. Smart lists refresh when opened. Every mailing checks current email permissions.</p>
                        {Object.keys(listErrors).length>0&&<p role="alert" className="text-sm text-red-700">{Object.values(listErrors).join(' ')}</p>}
                        <div className="flex gap-3"><button type="button" disabled={dirty||!visitIds.length||savingList||!listName.trim()||(listMode==='custom'&&!chosen.length)} onClick={saveList} className="rounded-lg bg-teal-800 px-3 py-2 text-sm text-white disabled:opacity-50">{savingList?'Saving…':saveListId?'Update event list':'Save event list'}</button>
                            {saveListId&&<button type="button" onClick={()=>{setSaveListId(null);setListName('');}} className="text-sm underline">Save as a new event list</button>}
                        </div>
                    </div>
                </details>
            </section>
            <section className="space-y-4 rounded-xl border bg-white p-5">
                <h2 className="font-semibold">Message and review</h2>
                <label className="block">Mailing name <input aria-label="Mailing name" className={`${input} ml-3`} value={name} onChange={e=>{setConfirmed(null);setName(e.target.value);}} maxLength={160} /></label>
                <label className="block">Template <select aria-label="Template" className={`${input} ml-3`} value={templateId} onChange={e=>{setConfirmed(null);setTemplateId(e.target.value);}}>
                    <option value="">Choose a compatible email template</option>{templates.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}
                </select></label>
                <p className="text-sm text-gray-600">Account/password templates and unsupported placeholders are excluded. Supported fields: first_name, last_name, full_name, email, school, event_name, event_date, event_time and event_location.</p>
                {template&&<div className="space-y-3 border-t pt-3"><p className="text-sm text-gray-500">This preview uses sample recipient and visit details. Images and external resources are hidden. Emails use the first matching registration for each address.</p>
                    <p className="text-sm">From: {template.from_name} &lt;{template.from_email}&gt;<br />Reply to: {template.reply_to_name} &lt;{template.reply_to_email}&gt;</p>
                    <p className="font-semibold">{previewSubject}</p>
                    <iframe title="Email preview" sandbox="" referrerPolicy="no-referrer" srcDoc={previewHtml} className="h-96 w-full rounded-lg border" />
                </div>}
                <label className="flex gap-3"><input type="checkbox" checked={confirmed} onChange={e=>setConfirmed(e.target.checked?template?.review:null)} />
                    <span>I reviewed the selected visits, email purpose, recipients and message.</span></label>
                {Object.keys(errors).length>0&&<div role="alert" className="text-red-700">{Object.values(errors).join(' ')}</div>}
                <button disabled={!confirmed||!chosen.length||chosen.length>1000||!template||!name.trim()||sending} onClick={send} className="rounded-lg bg-teal-800 px-5 py-3 text-white disabled:opacity-50">{sending?'Queueing…':`Queue ${chosen.length} emails`}</button>
                {chosen.length>1000&&<p role="alert">Select at most 1,000 addresses per mailing.</p>}
            </section>
        </>}
        <section className="space-y-4"><h2 className="text-xl font-semibold">School mailing history</h2>
            <p className="text-sm text-gray-600">Refresh to see worker results. “Sent” means the configured mail transport accepted the message. Inbox delivery is not guaranteed. Failed or uncertain deliveries need review before resending.</p>
            {mailings.map(m=><details key={m.id} className="rounded-xl border bg-white p-4"><summary>{m.name} · {new Date(m.created_at).toLocaleString()} · {Object.entries(m.counts).map(([key,value])=>`${key}: ${value}`).join(', ')}</summary>
                <ul className="mt-3 space-y-2">{m.recipients.map((r,i)=><li key={i}>{r.name} · {r.email} · {r.status}{r.detail&&<small className="block text-gray-600">{r.detail}</small>}</li>)}</ul>
            </details>)}
        </section>
    </main>;
}
