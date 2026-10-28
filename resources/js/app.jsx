import UserViewControls from './components/admin/UserViewControls';
import { installUserViewRequests, updateUserView } from './lib/userView';
import './bootstrap';

import { createInertiaApp, router } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { useEffect } from 'react';
import { Toaster } from 'sonner';
import '../css/app.css';

import AssistantDock from './components/ai/AssistantDock';
import FlashToaster from './components/ui/FlashToaster';
import FloatingContact from './components/ui/FloatingContact';
import MobileStickyCTA from './components/ui/MobileStickyCTA';
import { ConfirmHost } from './components/ui/ConfirmDialog';

// Map portal-path prefix → layout component. Pages under
// resources/js/pages/portal/<role>/ are automatically wrapped.
const PORTAL_LAYOUTS = {
  'portal/sales/': () => import('./components/layout/SalesLayout'),
  'portal/education/': () => import('./components/layout/EducationLayout'),
  'portal/english/': () => import('./components/layout/EnglishLayout'),
  'portal/immigration-adviser/': () => import('./components/layout/ImmigrationAdviserLayout'),
  'portal/immigration/': () => import('./components/layout/ImmigrationLayout'),
  'portal/accommodation/': () => import('./components/layout/AccommodationLayout'),
  'portal/finance/': () => import('./components/layout/FinanceLayout'),
  'portal/sub-agent/': () => import('./components/layout/SubAgentLayout'),
  'portal/agent/': () => import('./components/layout/AgentLayout'),
  'portal/lead/': () => import('./components/layout/LeadLayout'),
};

const pages = import.meta.glob('./pages/**/*.jsx');

function StartupReady() {
  useEffect(() => { window.luvepStartup?.ready(); }, []);
  return null;
}

createInertiaApp({
  resolve: async name => {
    const loadPage = pages[`./pages/${name}.jsx`];
    // Start the layout alongside the page; neither depends on the other's download.
    const ROLE_CHROME_PAGES = ['admin/PortalInvitations'];
    let loadLayout;
    if (name.startsWith('hr/')) loadLayout = () => import('./components/layout/HrLayout');
    else if (ROLE_CHROME_PAGES.includes(name) || name.startsWith('ai/')) loadLayout = () => import('./components/layout/RoleLayout');
    else if (name.startsWith('admin/')) loadLayout = () => import('./components/layout/AdminLayout');
    else {
      const match = Object.keys(PORTAL_LAYOUTS).find(prefix => name.startsWith(prefix));
      loadLayout = match ? PORTAL_LAYOUTS[match] : null;
    }
    const [page, layout] = await Promise.all([
      loadPage ? loadPage() : null,
      loadPage && loadLayout ? loadLayout() : null,
    ]);

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
    if (!page.default.layout && layout) {
      page.default.layout = page => <layout.default>{page}</layout.default>;
    }

    return page;

  },
  setup({ el, App, props }) {
    updateUserView(props.initialPage);
    installUserViewRequests(router, window.axios);
    router.on('navigate', ({ detail }) => updateUserView(detail.page));
    createRoot(el, {
      onUncaughtError(error) {
        console.error(error);
        window.luvepStartup?.fail();
      },
    }).render(
      <>
        <App {...props} />
        <UserViewControls initialPage={props.initialPage} />
        <FlashToaster />
        <AssistantDock initialPage={props.initialPage} />
        {!props.initialPage.component.startsWith('hr/') && (
          <>
            <FloatingContact />
            <MobileStickyCTA />
          </>
        )}
        <ConfirmHost />
        <Toaster position="top-right" richColors closeButton />
        <StartupReady />
      </>
    )
  },
}).catch((error) => {
  console.error(error);
  window.luvepStartup?.fail();
});
