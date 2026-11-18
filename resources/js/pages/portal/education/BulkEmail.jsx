// Education Bulk Email — reuses the sales BulkEmail screen under this portal's
// layout. Thin wrapper (own layout binding); BulkEmailController feeds the
// department basePath so posts target /portal/education/bulk-email.
import BulkEmailView from '@/pages/portal/sales/BulkEmail';

export default function EducationBulkEmail(props) {
    return <>
        <a href="/portal/education/school-mail" className="block mb-5 rounded-lg border border-teal-200 bg-teal-50 p-4 text-teal-900">
            <strong>School visit bulk email</strong><br />
            Choose Education school visits, including Jose Maria College and Tagum, and review registered or recorded-attended recipients.
        </a>
        <a href="/portal/education/rsvps#attendance" className="inline-block mb-5 text-sm font-semibold text-teal-800 underline">Manage event smart lists and custom attendee groups</a>
        <BulkEmailView {...props} />
    </>;
}
