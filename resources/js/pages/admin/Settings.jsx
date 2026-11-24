import React, { useState } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Save, Settings as SettingsIcon } from 'lucide-react';
import { toast } from 'sonner';

export default function Settings({ settings }) {
    const initial = Object.fromEntries(settings.map((s) => [s.key, s.value ?? '']));
    const [values, setValues] = useState(initial);
    const { post, processing } = useForm();
    const { props } = usePage();

    React.useEffect(() => {
        if (props.flash?.success) toast.success(props.flash.success);
    }, [props.flash?.success]);

    const grouped = settings.reduce((acc, s) => {
        const g = s.group || 'general';
        (acc[g] ||= []).push(s);
        return acc;
    }, {});

    const submit = (e) => {
        e.preventDefault();
        post('/admin/settings', {
            data: { values },
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title="Settings" />
            <div className="max-w-3xl mx-auto p-6">
                <header className="mb-8 flex items-center gap-3">
                    <div className="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center">
                        <SettingsIcon size={20} className="text-gray-700" />
                    </div>
                    <div>
                        <h1 className="text-2xl font-bold text-gray-900">Settings</h1>
                        <p className="text-sm text-gray-500">Edit fees and other app-wide values.</p>
                    </div>
                </header>

                <section className="mb-8 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-gray-200 bg-white p-5" aria-labelledby="settings-help-title">
                    <div><h2 id="settings-help-title" className="font-semibold text-gray-900">Help & documentation</h2><p className="mt-1 text-sm text-gray-600">Search guides and step-by-step instructions by department.</p></div>
                    <Link href="/help-center" className="inline-flex min-h-11 items-center rounded-lg bg-emerald-900 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-950 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-800">Open Help Center</Link>
                </section>

                <form onSubmit={submit} className="space-y-8">
                    {Object.entries(grouped).map(([group, rows]) => (
                        <section key={group} className="bg-white rounded-2xl border border-gray-100 shadow-sm">
                            <div className="px-6 py-4 border-b border-gray-100">
                                <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-gray-400">{group}</p>
                            </div>
                            <div className="p-6 space-y-5">
                                {rows.map((s) => (
                                    <div key={s.key}>
                                        <label className="block text-sm font-semibold text-gray-700 mb-1">
                                            {s.label || s.key}
                                        </label>
                                        <p className="text-xs text-gray-400 mb-2">
                                            <code className="bg-gray-100 px-1.5 py-0.5 rounded">{s.key}</code> · {s.type}
                                            {s.type === 'int' && s.key.endsWith('_cents') && values[s.key] && (
                                                <span className="ml-2 text-emerald-600">
                                                    = ${(Number(values[s.key]) / 100).toFixed(2)}
                                                </span>
                                            )}
                                        </p>
                                        {s.type === 'bool' ? (
                                            <select
                                                value={values[s.key]}
                                                onChange={(e) => setValues({ ...values, [s.key]: e.target.value })}
                                                className="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:border-gray-900"
                                            >
                                                <option value="1">True</option>
                                                <option value="0">False</option>
                                            </select>
                                        ) : (
                                            <input
                                                type={s.type === 'int' ? 'number' : 'text'}
                                                value={values[s.key]}
                                                onChange={(e) => setValues({ ...values, [s.key]: e.target.value })}
                                                className="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:outline-none focus:border-gray-900"
                                            />
                                        )}
                                    </div>
                                ))}
                            </div>
                        </section>
                    ))}

                    <div className="flex justify-end">
                        <button
                            type="submit"
                            disabled={processing}
                            className="flex items-center gap-2 px-6 py-2.5 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-gray-800 transition disabled:opacity-60"
                        >
                            <Save size={15} />
                            {processing ? 'Saving…' : 'Save settings'}
                        </button>
                    </div>
                </form>
            </div>
        </>
    );
}
