// A per-page snapshot prevents a stale tab from writing as a different user.
let token = 'none';
export function updateUserView(page) {
    token = page?.props?.userView?.token || 'none';
}
export function installUserViewRequests(router, axios) {
    router.on('before', (event) => { event.detail.visit.headers['X-Luvep-User-View'] = token; });
    axios.interceptors.request.use((config) => {
        const url = new URL(config.url || '', config.baseURL || window.location.origin);
        if (url.origin === window.location.origin) config.headers['X-Luvep-User-View'] = token;
        return config;
    });
    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, init) => {
        const url = new URL(input instanceof Request ? input.url : input, window.location.href);
        if (url.origin !== window.location.origin) return originalFetch(input, init);
        const headers = new Headers(init?.headers || (input instanceof Request ? input.headers : undefined));
        headers.set('X-Luvep-User-View', token);
        return originalFetch(input, { ...init, headers });
    };
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || new URL(form.action).origin !== window.location.origin) return;
        let field = form.querySelector('input[name="_user_view_token"]');
        if (!field) { field = document.createElement('input'); field.type = 'hidden'; field.name = '_user_view_token'; form.appendChild(field); }
        field.value = token;
    }, true);
}
