import './bootstrap';

import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Toaster } from 'sonner';
import '../css/app.css';

import HrLayout from './components/layout/HrLayout';
import AdminLayout from './components/layout/AdminLayout';
import SalesLayout from './components/layout/SalesLayout';
import EducationLayout from './components/layout/EducationLayout';
import EnglishLayout from './components/layout/EnglishLayout';
import ImmigrationLayout from './components/layout/ImmigrationLayout';
import ImmigrationAdviserLayout from './components/layout/ImmigrationAdviserLayout';
import AccommodationLayout from './components/layout/AccommodationLayout';
import FinanceLayout from './components/layout/FinanceLayout';
import AgentLayout from './components/layout/AgentLayout';
import SubAgentLayout from './components/layout/SubAgentLayout';
import LeadLayout from './components/layout/LeadLayout';
import RoleLayout from './components/layout/RoleLayout';
import FlashToaster from './components/ui/FlashToaster';
import FloatingContact from './components/ui/FloatingContact';
import MobileStickyCTA from './components/ui/MobileStickyCTA';
import { ConfirmHost } from './components/ui/ConfirmDialog';

// Map portal-path prefix → layout component. Pages under
// resources/js/pages/portal/<role>/ are automatically wrapped.
const PORTAL_LAYOUTS = {
  'portal/sales/': SalesLayout,
  'portal/education/': EducationLayout,
  'portal/english/': EnglishLayout,
  'portal/immigration-adviser/': ImmigrationAdviserLayout,
  'portal/immigration/': ImmigrationLayout,
  'portal/accommodation/': AccommodationLayout,
  'portal/finance/': FinanceLayout,
  'portal/sub-agent/': SubAgentLayout,
  'portal/agent/': AgentLayout,
  'portal/lead/': LeadLayout,
};

createInertiaApp({
  resolve: name => {
    const pages = import.meta.glob('./pages/**/*.jsx', { eager: true })
    let page = pages[`./pages/${name}.jsx`]

    // Defensive: if the page module isn't in the glob (typo in the
    // controller's inertia() name, or stale browser bundle from before
    // the page existed), surface a useful diagnostic instead of the
    // cryptic "Cannot read properties of undefined (reading 'default')"
    // that Inertia would otherwise hit on the next line.
    if (!page || !page.default) {
      const available = Object.keys(pages).slice(0, 10).join(', ');
      throw new Error(
        `Inertia page "${name}" not found. Looked up "./pages/${name}.jsx".\n` +
        `Available (first 10): ${available}\n` +
        `Hard-refresh the browser (Ctrl+Shift+R) to bypass any cached bundle.`
      );
    }

    // Admin-area pages that are grantable to non-admin staff via Module
    // Management must keep the user's OWN sidebar, not switch to the admin
    // chrome — so they adopt the signed-in user's role layout (admins still
    // resolve to AdminLayout through RoleLayout).
    const ROLE_CHROME_PAGES = ['admin/PortalInvitations'];

    if (name.startsWith('hr/')) {
      page.default.layout = page.default.layout || ((page) => <HrLayout>{page}</HrLayout>);
    } else if (ROLE_CHROME_PAGES.includes(name)) {
      page.default.layout = page.default.layout || ((page) => <RoleLayout>{page}</RoleLayout>);
    } else if (name.startsWith('admin/')) {
      page.default.layout = page.default.layout || ((page) => <AdminLayout>{page}</AdminLayout>);
    } else if (name.startsWith('ai/')) {
      // Role-agnostic authenticated pages (AI Assistant) reachable from every
      // portal — wrap in the sidebar chrome matching the signed-in user's role.
      page.default.layout = page.default.layout || ((page) => <RoleLayout>{page}</RoleLayout>);
    } else {
      const match = Object.keys(PORTAL_LAYOUTS).find((prefix) => name.startsWith(prefix));
      if (match) {
        const Layout = PORTAL_LAYOUTS[match];
        page.default.layout = page.default.layout || ((page) => <Layout>{page}</Layout>);
      }
    }

    return page;

  },
  setup({ el, App, props }) {
    createRoot(el).render(
      <>
        <App {...props} />
        <FlashToaster />
        <FloatingContact />
        <MobileStickyCTA />
        <ConfirmHost />
        <Toaster position="top-right" richColors closeButton />
      </>
    )
  },
})