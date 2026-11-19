import { Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { hrPath } from '@/lib/hrPath';
import { Errors } from '@/pages/hr/Dtr';

export const hrDocumentAccept = '.pdf,.doc,.docx,.png,.jpg,.jpeg,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg';

export default function PersonalDocumentUpload({ employeeId, documents = [], policy = false }) {
    const fileInput = useRef(null);
    const form = useForm({ employee_id: employeeId, document_id: '', title: policy ? 'Policy supporting document' : '', classification: 'employee_shared', file: null });
    return <details className="hr-panel"><summary><strong>{policy ? 'Upload a policy document to my record' : 'Upload my document'}</strong></summary>
        <p>Private to your employee record and authorized HR. Uploading a supporting file does not publish an official company policy.</p>
        <form className="hr-form" onSubmit={e => { e.preventDefault(); form.post(hrPath('/documents'), { forceFormData: true, preserveScroll: true, onSuccess: () => { form.reset(); if (fileInput.current) fileInput.current.value = ''; } }); }}>
            <fieldset className="hr-report-editor-fields" disabled={form.processing}>
                <Errors errors={form.errors} />
                {documents.some(d => d.can_upload) && <label>Document<select value={form.data.document_id} onChange={e => {
                    const doc = documents.find(d => String(d.id) === e.target.value);
                    form.setData({ ...form.data, document_id: e.target.value, title: doc?.title || '' });
                }}><option value="">Create a new document</option>{documents.filter(d => d.can_upload && !d.archived).map(d => <option key={d.id} value={d.id}>{d.title} · add version</option>)}</select></label>}
                <label>Document title<input required maxLength={200} disabled={!!form.data.document_id} value={form.data.title} onChange={e => form.setData('title', e.target.value)} /></label>
                <label>File · PDF, Word, PNG or JPEG, up to 10 MiB<input required type="file" ref={fileInput} accept={hrDocumentAccept} onChange={e => form.setData('file', e.target.files[0] || null)} /></label>
                <button className="hr-button" disabled={!form.data.file || form.processing}>{form.processing ? 'Uploading…' : 'Upload to my record'}</button>
            </fieldset>
        </form>
        {policy && <Link className="hr-text-link" href={hrPath('/documents?scope=mine')}>View my uploaded documents</Link>}
    </details>;
}
