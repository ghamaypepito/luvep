import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
const base='/portal/education/school-mail';
const input='rounded-lg border border-gray-300 px-3 py-2';
const escape=v=>String(v).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fill=(value,context,html=false)=>String(value||'').replace(/\{\{\s*([a-z0-9_]+)\s*\}\}/gi,(_,key)=>html?escape(context?.[key.toLowerCase()]??''):(context?.[key.toLowerCase()]??''));
export default function SchoolMail(props) {
    return <SchoolMailForm key={JSON.stringify(props.filters)} {...props} />;
}
function SchoolMailForm({visits=[],filters,recipients=[],templates=[],mailings=[]}) {
    const [visitIds,setVisits]=useState(filters.visit_ids.map(String));
    const [audience,setAudience]=useState(filters.audience);
    const [purpose,setPurpose]=useState(filters.purpose);
    const [selected,setSelected]=useState(new Set());
    const [search,setSearch]=useState('');
    const [name,setName]=useState('');
    const [templateId,setTemplateId]=useState('');
    const [confirmed,setConfirmed]=useState(false);
    const [sending,setSending]=useState(false);
    const [errors,setErrors]=useState({});
    const [submissionId]=useState(()=>crypto.randomUUID());
    const dirty=JSON.stringify(visitIds)!==JSON.stringify(filters.visit_ids.map(String)) || audience!==filters.audience || purpose!==filters.purpose;
    const rows=useMemo(()=>recipients.filter(r=>`${r.name} ${r.email}`.toLowerCase().includes(search.toLowerCase())),[recipients,search]);
    const chosen=recipients.filter(r=>r.eligible && selected.has(r.id));
    const preview=chosen[0]||rows.find(r=>r.eligible);
    const template=templates.find(t=>String(t.id)===templateId);
    const resetReview=()=>{setConfirmed(false);setSelected(new Set());};
    const toggleVisit=id=>{resetReview();setVisits(ids=>ids.includes(id)?ids.filter(v=>v!==id):[...ids,id]);};
    const toggle=id=>{setConfirmed(false);setSelected(prev=>{const next=new Set(prev);next.has(id)?next.delete(id):next.add(id);return next;});};
    const load=()=>router.get(base,{visit_ids:visitIds,audience,purpose},{preserveState:false});
    const send=()=>{
        if (!confirmed||dirty||sending||!chosen.length||!template||!name.trim()) return;
        setSending(true);setErrors({});
        router.post(base,{...filters,name,template_id:Number(templateId),recipient_keys:chosen.map(r=>r.id),submission_id:submissionId,confirmed:true},{
            preserveState:false,onError:e=>setErrors(e),onFinish:()=>setSending(false),
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
            <p className="text-sm text-gray-600">Attendance can be marked automatically after the event date. “Recorded attended” does not independently verify physical attendance. RSVP email addresses must be verified. Missing or conflicting permissions remain excluded. Older programme registrations without a visit link require support review and are not guessed into a school group.</p>
        </section>
        {dirty&&<p role="alert" className="text-amber-800">Filters changed. Load recipients again before reviewing or sending.</p>}
        {!dirty&&<>
            <section className="space-y-3 rounded-xl border bg-white p-5">
                <h2 className="font-semibold">Recipients: {chosen.length} selected · {recipients.filter(r=>r.eligible).length} eligible · {recipients.length} unique addresses</h2>
                <input aria-label="Search recipients" placeholder="Search name or email" className={input} value={search} onChange={e=>setSearch(e.target.value)} />
                <button className="ml-3 underline" onClick={()=>{setConfirmed(false);setSelected(new Set(rows.filter(r=>r.eligible).map(r=>r.id)));}}>Select eligible matches</button>
                <button className="ml-3 underline" onClick={resetReview}>Clear selection</button>
                <p className="text-sm text-gray-600">Selection is listed below even when search hides a recipient. One email per address across the selected visits.</p>
                {chosen.length>0&&<p className="text-sm">Selected: {chosen.map(r=>`${r.name} (${r.email})`).join(', ')}</p>}
                <ul className="max-h-80 overflow-y-auto divide-y">{rows.map(r=><li key={r.id} className="py-3"><label className="flex gap-3">
                    <input type="checkbox" disabled={!r.eligible} checked={selected.has(r.id)} onChange={()=>toggle(r.id)} />
                    <span>{r.name} · {r.email}<small className="block text-gray-600">{r.schools.join(', ')} · {r.attendance}</small>
                        {!r.eligible&&<small className="block text-amber-800">{r.reason}</small>}</span>
                </label></li>)}</ul>
                {!rows.length&&<p>No registrations match these visits and filters. Do not select a general event as a substitute.</p>}
            </section>
            <section className="space-y-4 rounded-xl border bg-white p-5">
                <h2 className="font-semibold">Message and review</h2>
                <label className="block">Mailing name <input aria-label="Mailing name" className={`${input} ml-3`} value={name} onChange={e=>{setConfirmed(false);setName(e.target.value);}} maxLength={160} /></label>
                <label className="block">Template <select aria-label="Template" className={`${input} ml-3`} value={templateId} onChange={e=>{setConfirmed(false);setTemplateId(e.target.value);}}>
                    <option value="">Choose a compatible email template</option>{templates.map(t=><option key={t.id} value={t.id}>{t.name}</option>)}
                </select></label>
                <p className="text-sm text-gray-600">Account/password templates and unsupported placeholders are excluded. Supported fields: first_name, last_name, full_name, email, school, event_name, event_date, event_time and event_location.</p>
                {template&&preview&&<div className="space-y-3 border-t pt-3"><p className="text-sm text-gray-500">Sample for {preview.name}. Visit fields use the first matching registration for that address.</p>
                    <p className="font-semibold">{fill(template.subject,preview.context)}</p><div className="prose" dangerouslySetInnerHTML={{__html:fill(template.body,preview.context,true)}} />
                </div>}
                <label className="flex gap-3"><input type="checkbox" checked={confirmed} onChange={e=>setConfirmed(e.target.checked)} />
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
