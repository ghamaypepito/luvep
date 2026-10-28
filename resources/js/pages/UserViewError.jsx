import { Head, usePage } from '@inertiajs/react';
export default function UserViewError({ status }) {
    const { userView } = usePage().props;
    return <main className="max-w-xl mx-auto p-8 pt-24"><Head title="User access"/><h1 className="text-2xl font-bold">{status === 403 ? 'This user does not have access' : 'This page is unavailable'}</h1><p className="mt-4 text-gray-600">You are acting as {userView?.target?.name}. Use the switch below to choose another user or return to Super Admin.</p></main>;
}
