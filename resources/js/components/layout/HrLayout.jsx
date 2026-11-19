import { UserViewButton } from '../admin/UserViewControls';
import { hrPath } from '@/lib/hrPath';
import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { LayoutDashboard, UserRound, UsersRound, LogOut, Clock3, Menu, X, ShieldCheck, ChevronRight, Settings } from 'lucide-react';
import NotificationBell from '../NotificationBell';
import ClockWidget, { HrClockProvider } from '../hr/ClockWidget';
import ProfileMenu from '../hr/ProfileMenu';
import PreviewBanner from '../hr/PreviewBanner';
import HrReturnLink from '../hr/HrReturnLink';
import { hrNavigation } from '../hr/hrNavigation';
import '../../../css/hr.css';
import '../../../css/hr-preview.css';

const icons = { overview: LayoutDashboard, workspace: Clock3, information: UserRound, team: UsersRound, settings: Settings };

export default function HrLayout({ children }) {
    const { hr, auth, flash, errors } = usePage().props;
    const pageUrl = usePage().url;
    const [menuOpen, setMenuOpen] = useState(false);
    const [density, setDensity] = useState(() => {
        try { return window.localStorage.getItem('luvep-hr-density') === 'split' ? 'split' : 'garden'; }
        catch { return 'garden'; }
    });
    const changeDensity = value => {
        setDensity(value);
        try { window.localStorage.setItem('luvep-hr-density', value); } catch { /* Browser storage may be unavailable. */ }
    };
    useEffect(() => setMenuOpen(false), [pageUrl]);
    const { groups, current, section } = hrNavigation(hr, auth?.user, pageUrl);
    const identity = hr?.employee?.name || auth?.user?.name || 'Your profile';
    const avatar = !hr?.preview && auth?.user?.avatar_url;
    const initials = identity.split(' ').filter(Boolean).slice(0, 2).map(word => word[0]).join('');
    const grouped = section && section.key !== 'overview';
    const clockKey = `${hr?.employee?.id || 'none'}:${hr?.preview ? 'preview' : 'self'}`;
    return <HrClockProvider key={clockKey} hr={hr}>
        <div className="hr-preview-toolbar">
            <span><i aria-hidden="true" />People & culture <small>Your HR workspace</small></span>
            <div className="hr-toolbar-actions">
                <div className="hr-density-control" role="group" aria-label="Workspace density">
                    <button type="button" aria-pressed={density === 'garden'} onClick={() => changeDensity('garden')}>Garden</button>
                    <button type="button" aria-pressed={density === 'split'} onClick={() => changeDensity('split')}>Split view</button>
                </div>
                <HrReturnLink hr={hr} />
            </div>
        </div>
        <div className={`hr-app hr-density-${density} hr-section-${section?.key || 'other'}`}>
            <a href="#hr-main" className="hr-skip-link">Skip to content</a>
            <aside className="hr-sidebar">
                <Link href={hrPath('/dashboard')} aria-label="Luvep HR home" className="hr-logo">
                    <img src="/images/luvep-hr-logo.png" alt="Luv, eP." /><span>People & culture</span>
                </Link>
                <button type="button" className="hr-menu-toggle" aria-expanded={menuOpen} aria-controls="hr-navigation" onClick={() => setMenuOpen(!menuOpen)}>
                    {menuOpen ? <X size={20} /> : <Menu size={20} />} Menu <span>{section?.label || 'People & culture'}</span>
                </button>
                <div id="hr-navigation" className={`hr-nav-content${menuOpen ? ' is-open' : ''}`}>
                    <nav aria-label="HR navigation">
                        {groups.map(group => {
                            const Icon = icons[group.key];
                            return <Link key={group.key} href={group.tabs[0].href} aria-current={section?.key === group.key ? 'page' : undefined}><Icon size={19} aria-hidden="true" />{group.label}</Link>;
                        })}
                    </nav>
                    <div className="hr-sidebar-note"><ShieldCheck size={21} aria-hidden="true" /><strong>Your HR space</strong><p>Personnel records only.<br />No general Admin activity.</p></div>
                    <Link className="hr-sidebar-person" href={hrPath('/profile')}>
                        <span className="hr-avatar">{avatar ? <img src={avatar} alt="" /> : initials}</span>
                        <span><strong>{identity}</strong><small>{hr?.employee?.position || (hr?.preview ? 'Employee preview' : 'Your profile')}</small></span>
                        <ChevronRight size={16} aria-hidden="true" />
                    </Link>
                    <button className="hr-sign-out" onClick={() => router.post('/logout')}><LogOut size={15} />Sign out</button>
                </div>
            </aside>
            <div className="hr-workspace">
                <header className="hr-topbar">
                    <span><span className="hr-topbar-label">People & culture</span><span className="hr-breadcrumb-separator">/</span><strong>{section?.label || 'HR Portal'}</strong></span>
                    <div className="hr-actions"><UserViewButton /><NotificationBell /><ProfileMenu /></div>
                </header>
                <PreviewBanner />
                <main id="hr-main" tabIndex={-1}>
                    {flash?.success && <p className="hr-feedback" role="status">{flash.success}</p>}
                    {(flash?.error || Object.keys(errors || {}).length > 0) && <p className="hr-feedback hr-error" role="alert">{flash?.error || Object.values(errors).flat()[0]}</p>}
                    {grouped && <>
                        <div className="hr-page-heading hr-group-heading"><h1>{section.label}</h1><p>{section.description}</p></div>
                        <nav className="hr-section-tabs" aria-label={section.label}>{section.tabs.map(link => <Link key={link.href} href={link.href} aria-current={current?.href === link.href ? 'page' : undefined}>{link.label}</Link>)}</nav>
                    </>}
                    {current?.href === hrPath('/dtr') && <ClockWidget />}
                    <div className={grouped ? 'hr-group-content' : undefined}>{children}</div>
                </main>
                <footer className="hr-footer">Luv, eP. · Your people, your workplace.</footer>
            </div>
        </div>
    </HrClockProvider>;
}
