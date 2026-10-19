import { router, usePage } from "@inertiajs/react";
import { Eye } from "lucide-react";

export default function PreviewBanner() {
    const { hr } = usePage().props;
    if (!hr?.preview) return null;
    const exit = () =>
        router.post(
            hr.portal_mode ? "/portal/hr/preview/exit" : window.location.host === new URL(hr.url).host
                ? "/preview/exit"
                : "/hr/preview/exit",
        );
    return (
        <div className="hr-preview-banner" role="status">
            <Eye size={18} />
            <span>
                Viewing <strong>{hr.preview.employee_name}</strong> · Read-only
            </span>
            <button onClick={exit}>Return to admin</button>
        </div>
    );
}
