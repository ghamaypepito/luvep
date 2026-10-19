import { Head, Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

export default function Dashboard({ profile }) {
    const firstName = profile.user?.name?.split(' ')[0] || 'there';
    return <><Head title="Your workplace · Luvep" /><div className="hr-page-heading"><h1>Hello, {firstName}.</h1><p>Your working day, in one place.</p></div>
        <section className="hr-panel hr-welcome"><div><h2>Your employee profile</h2><p>Keep your contact details up to date and review your employment information.</p><Link className="hr-button" href="/profile">View profile <ArrowRight size={16} /></Link></div><dl className="hr-detail-list"><div><dt>Department</dt><dd>{profile.department || 'Awaiting HR setup'}</dd></div><div><dt>Position</dt><dd>{profile.position || 'Awaiting HR setup'}</dd></div><div><dt>Manager</dt><dd>{profile.manager || 'Not yet assigned'}</dd></div></dl></section>
    </>;
}
