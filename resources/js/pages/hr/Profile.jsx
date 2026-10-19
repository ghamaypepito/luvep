import { Head, useForm, usePage } from '@inertiajs/react';

export default function Profile({ profile, readOnly = false }) {
    const { hr } = usePage().props;
    const locked = readOnly || !!hr?.preview;
    const form = useForm({ phone: profile.user?.phone || '', emergency_contact: profile.emergency_contact || '' });
    const submit = event => { event.preventDefault(); if (!locked) form.patch('/profile', { preserveScroll: true }); };
    return <><Head title="My profile · Luvep" /><div className="hr-page-heading"><h1>{readOnly ? 'Employee profile' : 'My profile'}</h1><p>Your details, securely held in one place.</p></div>
        <section className="hr-panel"><div className="hr-person-heading"><span className="hr-avatar hr-avatar-large">{profile.user.avatar_url ? <img src={profile.user.avatar_url} alt="" /> : profile.user.name?.slice(0, 1)}</span><div><h2>{profile.user.name}</h2><p>{profile.user.email}</p></div></div>
        <dl className="hr-profile-grid">{[['Employee ID', profile.employee_number], ['Department', profile.department], ['Position', profile.position], ['Office', profile.office], ['Employment type', profile.employment_type], ['Manager', profile.manager], ['Operations reviewer', profile.reviewer], ['Time zone', profile.schedule?.timezone]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || 'Awaiting HR setup'}</dd></div>)}</dl></section>
        <section className="hr-panel"><h2>Contact information</h2><p>Employment details are managed by HR.</p><form onSubmit={submit} className="hr-form"><label>Phone number<input type="tel" value={form.data.phone} disabled={locked || form.processing} onChange={e => form.setData('phone', e.target.value)} autoComplete="tel" /></label>{form.errors.phone && <p className="hr-error" role="alert">{form.errors.phone}</p>}
        {'emergency_contact' in profile && <label>Emergency contact<textarea value={form.data.emergency_contact} disabled={locked || form.processing} onChange={e => form.setData('emergency_contact', e.target.value)} maxLength={1000} rows={3} /><span>Contact name, relationship and phone number.</span></label>}
        {form.errors.emergency_contact && <p className="hr-error" role="alert">{form.errors.emergency_contact}</p>}
        {!locked && <div><button className="hr-button" disabled={form.processing}>{form.processing ? 'Saving…' : 'Save details'}</button>{form.recentlySuccessful && <span role="status" className="hr-saved">Saved</span>}</div>}</form></section></>;
}
