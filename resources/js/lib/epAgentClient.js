const csrf = () => {
    const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
    return cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }
        : { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' };
};
async function request(url, { method = 'GET', body, signal } = {}) {
    const response = await fetch(url, {
        method, signal, credentials: 'same-origin', cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest',
            ...(method !== 'GET' ? { 'Content-Type': 'application/json', ...csrf() } : {}) },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    let data = {};
    try { data = await response.json(); } catch { /* Private errors may be empty or HTML. */ }
    return { ok: response.ok, status: response.status, data: data || {} };
}
const query = values => new URLSearchParams(Object.entries(values).filter(([, value]) => value !== null && value !== undefined && value !== '')).toString();
export const epAgentClient = {
    list: ({ before, signal } = {}) => request(`/api/ep-agent/conversations${before ? `?before=${before}` : ''}`, { signal }),
    conversation: (id, { before, signal } = {}) => request(`/api/ep-agent/conversations/${id}${before ? `?before=${before}` : ''}`, { signal }),
    create: (portal, leadId, title) => request('/api/ep-agent/conversations', { method: 'POST', body: { portal, lead_id: leadId || null, title } }),
    append: (id, content) => request(`/api/ep-agent/conversations/${id}/messages`, { method: 'POST', body: { content } }),
    run: (id, key) => request(`/api/ep-agent/conversations/${id}/runs`, { method: 'POST', body: { idempotency_key: key } }),
    archive: id => request(`/api/ep-agent/conversations/${id}`, { method: 'DELETE' }),
    context: (values, { signal } = {}) => request(`/api/ep-agent/context?${query(values)}`, { signal }),
    manager: (portal, { before, attention, signal } = {}) => request(`/api/ep-agent/manager?${query({ portal, before, attention: attention ? 1 : undefined })}`, { signal }),
    detail: (kind, id, portal, { signal } = {}) => request(`/api/ep-agent/manager/${kind}/${id}?${query({ portal })}`, { signal }),
};
