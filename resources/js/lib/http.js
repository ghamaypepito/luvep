// Session-authenticated JSON requests. The cookie is refreshed by Laravel;
// the initial HTML meta token can be stale after an Inertia login.
const csrfHeaders = () => {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    if (cookie) return { "X-XSRF-TOKEN": decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) };
    return { "X-CSRF-TOKEN": document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") || "" };
};

export async function getJson(url) {
    const res = await fetch(url, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
    });
    let data = null;
    try { data = await res.json(); } catch { data = null; }
    return { ok: res.ok, status: res.status, data };
}

export async function postJson(url, body) {
    const res = await fetch(url, {
        method: "POST",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...csrfHeaders(),
        },
        credentials: "same-origin",
        body: JSON.stringify(body || {}),
    });
    let data = null;
    try { data = await res.json(); } catch { data = null; }
    return { ok: res.ok, status: res.status, data: data || {} };
}
