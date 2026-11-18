import DashboardLayout from './DashboardLayout';
import '../../../css/affiliate.css';

export default function AffiliateWorkspaceLayout({ children, nav, subtitle, footerNav = [] }) {
    return (
        <div className="affiliate-portal">
            <DashboardLayout
                brand={<img src="/images/ep-logo.png" alt="EP" className="affiliate-brand" />}
                subtitle={subtitle}
                accent="affiliate-nav-active"
                nav={nav}
                footerNav={footerNav}
            >
                <div className="affiliate-page">{children}</div>
            </DashboardLayout>
        </div>
    );
}
