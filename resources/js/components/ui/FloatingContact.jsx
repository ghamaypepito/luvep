import useGlobalPage from "./useGlobalPage";

// Floating WhatsApp button (desktop) — sits just above the existing
// ScrollToTop / ChatBot trigger at bottom-right. Messenger moved to the
// Footer socials row so it lives alongside Instagram + Facebook +
// LinkedIn instead of stacking another floating bubble.
//
// Hidden on admin/portal/auth surfaces because those aren't public
// landing pages.
export default function FloatingContact() {
    const page = useGlobalPage();
    const contact = page?.props?.contact;
    const url = page?.url || "";

    // Public site only — kill switch on internal surfaces.
    const isInternal =
        page?.component?.startsWith("hr/") ||
        url.startsWith("/admin") ||
        url.startsWith("/portal") ||
        url.startsWith("/login");
    if (isInternal) return null;

    const whatsappHref = contact?.whatsapp
        ? `https://wa.me/${String(contact.whatsapp).replace(/[^\d]/g, "")}?text=${encodeURIComponent(
              "Hi EP — I'd like to know more about migrating to New Zealand."
          )}`
        : null;

    if (!whatsappHref) return null;

    return (
        <div className="hidden md:flex fixed right-6 bottom-28 z-40 flex-col items-end gap-3 pointer-events-none">
            <a
                href={whatsappHref}
                target="_blank"
                rel="noopener noreferrer"
                aria-label="Chat on WhatsApp"
                title="Chat on WhatsApp"
                className="pointer-events-auto group relative w-14 h-14 rounded-full bg-[#25D366] shadow-[0_15px_35px_-5px_rgba(0,0,0,0.4)] flex items-center justify-center hover:scale-110 active:scale-95 transition-all"
            >
                <svg viewBox="0 0 32 32" className="w-7 h-7" fill="white" aria-hidden="true">
                    <path d="M16 .396C7.163.396 0 7.559 0 16.396c0 2.823.74 5.587 2.144 8.013L.057 32l7.81-2.049a15.94 15.94 0 0 0 8.133 2.234h.007C24.844 32.185 32 25.022 32 16.185 32 7.348 24.844.396 16 .396zm0 28.84a13.23 13.23 0 0 1-6.74-1.846l-.484-.286-4.638 1.217 1.238-4.515-.316-.504a13.21 13.21 0 0 1-2.027-7.106c0-7.31 5.946-13.257 13.27-13.257 3.543 0 6.872 1.379 9.378 3.884a13.18 13.18 0 0 1 3.892 9.387c-.005 7.31-5.95 13.026-13.273 13.026zm7.27-9.93c-.398-.2-2.357-1.164-2.722-1.297-.366-.133-.633-.2-.9.2-.266.398-1.031 1.297-1.265 1.563-.234.266-.467.3-.866.1-.398-.2-1.683-.62-3.205-1.978-1.185-1.057-1.985-2.363-2.218-2.761-.234-.398-.025-.613.175-.812.18-.18.398-.467.598-.7.2-.234.266-.398.4-.665.133-.266.067-.498-.033-.698-.1-.2-.9-2.166-1.231-2.963-.324-.78-.652-.673-.9-.687-.234-.013-.498-.013-.766-.013-.266 0-.7.1-1.066.498-.366.398-1.397 1.367-1.397 3.333 0 1.967 1.43 3.866 1.63 4.132.2.266 2.814 4.298 6.819 6.024.953.412 1.694.659 2.272.844.954.304 1.823.262 2.51.16.766-.115 2.357-.964 2.69-1.896.332-.93.332-1.728.232-1.896-.1-.166-.366-.266-.766-.466z" />
                </svg>
                <span className="absolute right-full mr-3 px-3 py-1.5 rounded-full bg-[#282728] text-white text-[9px] font-bold uppercase tracking-[0.2em] whitespace-nowrap opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none">
                    WhatsApp
                </span>
            </a>
        </div>
    );
}
