const resolvedPages = new Map();

// Re-exported pages can share a component across portals. Keep automatic
// layouts on stable, per-page wrappers instead of mutating that component.
export function withPageLayout(name, page, layout) {
    const Page = page.default;
    const Layout = layout?.default;
    if (Page.layout || !Layout) return page;

    const cached = resolvedPages.get(name);
    if (cached?.Page === Page && cached.Layout === Layout) return cached.module;

    function WrappedPage(props) {
        return <Page {...props} />;
    }
    WrappedPage.displayName = Page.displayName || Page.name || name;
    WrappedPage.layout = children => <Layout>{children}</Layout>;

    const resolved = { ...page, default: WrappedPage };
    resolvedPages.set(name, { Page, Layout, module: resolved });
    return resolved;
}
