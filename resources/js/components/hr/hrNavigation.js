import { hrPath } from '@/lib/hrPath';

// Navigation reflects existing abilities; endpoints remain the authorization boundary.
export function hrNavigation(hr, user, url) {
    const path = url.split('?')[0];
    const mine = new URLSearchParams(url.split('?')[1]).get('scope') === 'mine';
    const admin = !hr?.preview && ['admin', 'super_admin'].includes(user?.role);
    const permission = hr?.permissions || {};
    const documents = admin || Object.entries(permission).some(([key, value]) => key.startsWith('documents.') && value);
    const equipment = admin || permission['operations.manage'];
    const tab = (route, label) => ({ href: hrPath(route), label });
    const groups = [
        { key: 'overview', label: 'Overview', tabs: [tab('/dashboard', 'Overview')] },
        { key: 'workspace', label: 'My workspace', description: 'Your time records, report changes and time away.', tabs: [
            ...(hr?.employee ? [tab('/dtr', 'Time records'), ...(!hr.preview ? [tab('/dtr/changes?scope=mine', 'DTR changes')] : []), tab('/leave', 'Leave')] : []),
            ...(!hr?.can_view_staff_status ? [tab('/calendar', 'Calendar'), tab('/reports', 'Reports')] : []),
        ] },
        { key: 'information', label: 'My information', description: 'Your details, documents and workplace resources, together.', tabs: [
            tab('/profile', 'My profile'),
            ...(hr?.employee ? [tab('/documents?scope=mine', 'My documents')] : documents ? [tab('/documents', 'Employee documents')] : []),
            tab('/policies?scope=mine', 'Policies & guide'),
            ...(hr?.employee ? [tab('/equipment?scope=mine', 'My equipment')] : equipment ? [tab('/equipment', 'Equipment')] : []),
            ...(!hr?.preview && hr?.employee && !equipment ? [tab('/equipment', 'Equipment tasks')] : []),
            tab('/requests', 'HR support'), tab('/checklists', 'Onboarding & exits'), tab('/milestones', 'Milestones'),
        ] },
        { key: 'team', label: 'Team workspace', description: 'Your people, attendance and reviews, in one place.', tabs: [
            ...(hr?.can_view_staff_status ? [tab('/team', 'Staff status')] : []),
            ...(permission['leave.review'] ? [tab('/leave/review', 'Leave decisions')] : []),
            ...(permission['dtr.review'] ? [tab('/dtr/review', 'Time reviews')] : []),
            ...(hr?.can_view_dtr_changes ? [tab('/dtr/changes', 'DTR acknowledgements')] : []),
            ...(permission['dtr.correct'] ? [tab('/dtr/corrections', 'Time corrections')] : []),
            ...(permission.people ? [tab('/people', 'People')] : []),
            ...(hr?.can_view_staff_status ? [tab('/reports', 'Reports'), tab('/calendar', 'Calendar')] : []),
            ...(documents && hr?.employee ? [tab('/documents', 'Employee documents')] : []),
            ...(equipment && hr?.employee ? [tab('/equipment', 'Employee equipment')] : []),
            ...(permission['policies.publish'] ? [tab('/policies', 'Official policies')] : []),
        ] },
        { key: 'settings', label: 'Settings', description: 'HR access and activity.', tabs: [
            ...(permission['access.manage'] ? [tab('/access', 'Access')] : []),
            ...(permission.audit ? [tab('/audit', 'Activity & delivery')] : []),
            ...(permission.preview ? [tab('/preview', 'Employee preview')] : []),
        ] },
    ].filter(group => group.tabs.length);
    const matches = link => {
        if (link.href === hrPath('/dashboard') && ['/', '/portal/hr', '/portal/hr/'].includes(path)) return true;
        const [target, query] = link.href.split('?');
        if (target === hrPath('/dtr/changes')) return path === target && mine === (query === 'scope=mine');
        if (['/documents', '/policies', '/equipment'].some(route => target === hrPath(route))) return path === target && mine === (query === 'scope=mine');
        return path === target || path.startsWith(`${target}/`);
    };
    const current = groups.flatMap(group => group.tabs.map(link => ({ ...link, group }))).filter(matches).sort((a,b) => b.href.length-a.href.length)[0];
    return { groups, current, section: current?.group };
}
