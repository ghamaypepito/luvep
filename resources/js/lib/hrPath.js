export const hrPath = (path) => window.location.pathname.startsWith("/portal/hr") ? `/portal/hr${path}` : path;
