import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import TaskDetailModal from './TaskDetailModal';

// Only open records already returned by this portal's authorized task query.
// A notification URL never grants access to an arbitrary task or client.
export default function AssignedTaskFocus({ tasks = [] }) {
    const { url = '' } = usePage();
    const [closed, setClosed] = useState(false);
    const destination = new URL(url, 'https://luvep.local');
    const rawId = destination.searchParams.get('task');
    const taskId = rawId && /^\d+$/.test(rawId) ? Number(rawId) : null;
    if (!taskId || !Number.isSafeInteger(taskId) || closed) return null;

    const task = tasks.find(item => Number(item.id) === taskId);
    const close = () => {
        setClosed(true);
        destination.searchParams.delete('task');
        router.get(destination.pathname + destination.search, {}, {
            preserveScroll: true, preserveState: true, replace: true,
        });
    };
    if (!task) return <div role="status" className="rounded-lg border border-gray-200 bg-white px-4 py-3 text-sm text-gray-600">
        This task is not available in your current task view.
        <button type="button" onClick={close} className="ml-2 font-semibold text-gray-900 underline underline-offset-2">Dismiss</button>
    </div>;

    return <TaskDetailModal task={task} onClose={close} />;
}
