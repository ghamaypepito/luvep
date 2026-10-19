import { Link } from "@inertiajs/react";
import { Calendar, Phone } from "react-feather";
import useGlobalPage from "./useGlobalPage";

// Bottom-fixed CTA bar shown only on mobile (`<md:`). Four-up:
//   1. Book Free   → /booking
//   2. WhatsApp    → wa.me/<number>
//   3. Call        → tel:
// Hidden on admin/portal/auth surfaces. Each cell collapses gracefully if
// its underlying contact channel is unconfigured.
export default function MobileStickyCTA() {
    const page = useGlobalPage();
    const contact = page?.props?.contact;
    const url = page?.url || "";

    const isInternal =
        page?.component?.startsWith("hr/") ||
        url.startsWith("/admin") ||
        url.startsWith("/portal") ||
        url.startsWith("/login");
    if (isInternal) return null;

    const whatsappHref = contact?.whatsapp
        ? `https://wa.me/${String(contact.whatsapp).replace(/[^\d]/g, "")}?text=${encodeURIComponent(
              "Hi EP — I'd like to know more about migrating to NZ."
          )}`
        : null;
    const phoneHref = contact?.phone
        ? `tel:${String(contact.phone).replace(/\s+/g, "")}`
        : null;

    const cells = [
        {
            key: "book",
            as: Link,
            href: "/booking",
            label: "Book Free",
            sublabel: "1:1 consult",
            icon: <Calendar size={18} strokeWidth={2.25} />,
            tone: "bg-[#436235] text-white",
        },
        whatsappHref && {
            key: "wa",
            as: "a",
            href: whatsappHref,
            target: "_blank",
            rel: "noopener noreferrer",
            label: "WhatsApp",
            sublabel: "Reply fast",
            icon: (
                <svg viewBox="0 0 32 32" className="w-[18px] h-[18px]" fill="currentColor" aria-hidden="true">
                    <path d="M16 .396C7.163.396 0 7.559 0 16.396c0 2.823.74 5.587 2.144 8.013L.057 32l7.81-2.049a15.94 15.94 0 0 0 8.133 2.234h.007C24.844 32.185 32 25.022 32 16.185 32 7.348 24.844.396 16 .396zm7.27 19.91c-.398-.2-2.357-1.164-2.722-1.297-.366-.133-.633-.2-.9.2-.266.398-1.031 1.297-1.265 1.563-.234.266-.467.3-.866.1-.398-.2-1.683-.62-3.205-1.978-1.185-1.057-1.985-2.363-2.218-2.761-.234-.398-.025-.613.175-.812.18-.18.398-.467.598-.7.2-.234.266-.398.4-.665.133-.266.067-.498-.033-.698-.1-.2-.9-2.166-1.231-2.963-.324-.78-.652-.673-.9-.687-.234-.013-.498-.013-.766-.013-.266 0-.7.1-1.066.498-.366.398-1.397 1.367-1.397 3.333 0 1.967 1.43 3.866 1.63 4.132.2.266 2.814 4.298 6.819 6.024 2.708 1.168 3.225.937 3.81.884.586-.053 1.89-.772 2.16-1.518.265-.747.265-1.387.18-1.518-.085-.133-.339-.213-.737-.413z" />
                </svg>
            ),
            tone: "bg-[#25D366] text-white",
        },
        phoneHref && {
            key: "call",
            as: "a",
            href: phoneHref,
            label: "Call",
            sublabel: "NZ team",
            icon: <Phone size={18} strokeWidth={2.25} />,
            tone: "bg-[#282728] text-white",
        },
    ].filter(Boolean);

    return (
        <div
            className="md:hidden fixed bottom-0 inset-x-0 z-40 bg-white/95 backdrop-blur-md border-t border-gray-200 shadow-[0_-8px_24px_-8px_rgba(0,0,0,0.18)]"
            style={{ paddingBottom: "env(safe-area-inset-bottom)" }}
        >
            <div className="grid grid-cols-3 gap-1.5 p-1.5">
                {cells.map((c) => {
                    const Tag = c.as === Link ? Link : "a";
                    const tagProps =
                        c.as === Link
                            ? { href: c.href }
                            : { href: c.href, target: c.target, rel: c.rel };
                    return (
                        <Tag
                            key={c.key}
                            {...tagProps}
                            className={`flex flex-col items-center justify-center gap-0.5 py-2 rounded-lg active:scale-[0.97] transition-transform ${c.tone}`}
                        >
                            {c.icon}
                            <span className="text-[10px] font-bold uppercase tracking-wider leading-none">
                                {c.label}
                            </span>
                            <span className="text-[8px] uppercase tracking-widest opacity-70 leading-none">
                                {c.sublabel}
                            </span>
                        </Tag>
                    );
                })}
            </div>
        </div>
    );
}
