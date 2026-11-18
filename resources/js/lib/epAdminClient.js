async function get(url, signal) {
    const response = await fetch(url, { signal, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
    let data = {}; try { data = await response.json(); } catch { /* Never treat an HTML error as success. */ }
    return { ok: response.ok, status: response.status, data: data || {} };
}
const query = values => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== null && value !== undefined && value !== '')).toString();
export const epAdminClient = {
    overview: (period, { signal } = {}) => get(`/api/ep-agent/admin/overview?${query({ period })}`, signal),
    runs: ({ period, portal, state, before, signal } = {}) => get(`/api/ep-agent/admin/runs?${query({ period, portal, state, before })}`, signal),
    run: (id, { signal } = {}) => get(`/api/ep-agent/admin/runs/${id}`, signal),
};
