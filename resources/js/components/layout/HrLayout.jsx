import { Link, router, usePage } from '@inertiajs/react';
import { LayoutDashboard, UserRound, UsersRound, LogOut, ArrowUpRight, Clock3, ClipboardCheck } from 'lucide-react';
import ProfileMenu from '../hr/ProfileMenu';
import PreviewBanner from '../hr/PreviewBanner';
import '../../../css/hr.css';

export default function HrLayout({ children }) {
    const { hr } = usePage().props;
    const path = usePage().url.split('?')[0];
    const links = [['/', 'Overview', LayoutDashboard], ['/profile', 'My profile', UserRound]];
    if (hr?.employee) links.push(['/dtr', 'My time records', Clock3]);
    if (hr?.permissions?.['dtr.review']) links.push(['/dtr/review', 'Time reviews', ClipboardCheck]);
    if (hr?.permissions?.people) links.push(['/people', 'People', UsersRound]);
    return <div className="hr-app">
        <aside className="hr-sidebar">
            <a href="/" aria-label="Luvep HR home" className="hr-logo"><img src="/images/luvep-hr-logo.png" alt="Luv, eP." /><span>People & culture</span></a>
            <nav aria-label="HR navigation">{links.map(([href, label, Icon]) => <Link href={href} key={href} aria-current={path === href ? 'page' : undefined}><Icon size={19} />{label}</Link>)}</nav>
            <div className="hr-sidebar-footer"><a href="https://luvep.com/">Back to Luvep <ArrowUpRight size={16} /></a><button onClick={() => router.post('/logout')}><LogOut size={16} />Sign out</button></div>
        </aside>
        <div className="hr-workspace"><header className="hr-topbar"><span>Employee portal</span><ProfileMenu /></header><PreviewBanner /><main id="hr-main">{children}</main><footer className="hr-footer">Luv, eP. · Your people, your workplace.</footer></div>
    </div>;
}
