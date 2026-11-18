export async function postTaskComment(taskId, body) {
    // Inertia navigation keeps the initial HTML meta token after login rotates
    // the session. Laravel refreshes this cookie with each response instead.
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    const csrf = cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : '';
    const response = await fetch(`/api/tasks/${taskId}/comments`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': csrf,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ body }),
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    return response.json();
}
