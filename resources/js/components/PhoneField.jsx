import { useState, useMemo, useRef, useEffect } from "react";
import { ChevronDown, Search } from "lucide-react";

// International phone input — a searchable country dropdown (flag + dial code)
// beside the national number. Emits the full "+<dial> <number>" string via
// onChange. Self-contained: flag emoji are derived from the ISO code, no lib.

const COUNTRIES = [
    { n: "New Zealand", iso: "NZ", d: "64" },
    { n: "Australia", iso: "AU", d: "61" },
    { n: "Philippines", iso: "PH", d: "63" },
    { n: "India", iso: "IN", d: "91" },
    { n: "China", iso: "CN", d: "86" },
    { n: "United States", iso: "US", d: "1" },
    { n: "United Kingdom", iso: "GB", d: "44" },
    { n: "Canada", iso: "CA", d: "1" },
    { n: "Nepal", iso: "NP", d: "977" },
    { n: "Sri Lanka", iso: "LK", d: "94" },
    { n: "Pakistan", iso: "PK", d: "92" },
    { n: "Bangladesh", iso: "BD", d: "880" },
    { n: "Vietnam", iso: "VN", d: "84" },
    { n: "Thailand", iso: "TH", d: "66" },
    { n: "Indonesia", iso: "ID", d: "62" },
    { n: "Malaysia", iso: "MY", d: "60" },
    { n: "Singapore", iso: "SG", d: "65" },
    { n: "Japan", iso: "JP", d: "81" },
    { n: "South Korea", iso: "KR", d: "82" },
    { n: "Fiji", iso: "FJ", d: "679" },
    { n: "United Arab Emirates", iso: "AE", d: "971" },
    { n: "Saudi Arabia", iso: "SA", d: "966" },
    { n: "Qatar", iso: "QA", d: "974" },
    { n: "South Africa", iso: "ZA", d: "27" },
    { n: "Germany", iso: "DE", d: "49" },
    { n: "France", iso: "FR", d: "33" },
    { n: "Italy", iso: "IT", d: "39" },
    { n: "Spain", iso: "ES", d: "34" },
    { n: "Netherlands", iso: "NL", d: "31" },
    { n: "Ireland", iso: "IE", d: "353" },
    { n: "Brazil", iso: "BR", d: "55" },
    { n: "Mexico", iso: "MX", d: "52" },
    { n: "Nigeria", iso: "NG", d: "234" },
    { n: "Kenya", iso: "KE", d: "254" },
    { n: "Egypt", iso: "EG", d: "20" },
    { n: "Turkey", iso: "TR", d: "90" },
    { n: "Russia", iso: "RU", d: "7" },
    { n: "Hong Kong", iso: "HK", d: "852" },
    { n: "Taiwan", iso: "TW", d: "886" },
    { n: "Myanmar", iso: "MM", d: "95" },
    { n: "Cambodia", iso: "KH", d: "855" },
];

const flag = (iso) => iso.toUpperCase().replace(/./g, (c) => String.fromCodePoint(127397 + c.charCodeAt(0)));

const INTERNATIONAL = { n: "International", iso: "", d: "" };
const COUNTRY_PREFIXES = [...COUNTRIES].sort((a, b) => b.d.length - a.d.length);
function parsePhone(value) {
    const raw = (value || "").trim();
    if (raw.startsWith("+")) {
        const digits = raw.replace(/\D/g, "");
        const match = COUNTRY_PREFIXES.find((c) => digits.startsWith(c.d));
        if (match) return { country: match, number: digits.slice(match.d.length) };
        // No prefix guessing: retain the full international number when its
        // country is absent from the convenience list.
        return { country: INTERNATIONAL, number: `+${digits}` };
    }
    return { country: COUNTRIES[0], number: raw };
}

export default function PhoneField({ value = "", onChange, error, placeholder = "Phone number" }) {
    const initial = useMemo(() => parsePhone(value), []);
    const [country, setCountry] = useState(initial.country);
    const [number, setNumber] = useState(initial.number);
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState("");
    const ref = useRef(null);
    const lastEmitted = useRef(null);

    useEffect(() => {
        // Parent form draft loads/resets must update the visible input. Keep
        // local country selection when the parent merely echoes our change.
        if (value === lastEmitted.current) return;
        const parsed = parsePhone(value);
        setCountry(parsed.country);
        setNumber(parsed.number);
    }, [value]);

    useEffect(() => {
        const onDoc = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false); };
        document.addEventListener("mousedown", onDoc);
        return () => document.removeEventListener("mousedown", onDoc);
    }, []);

    const emit = (c, n) => {
        const next = !n || n === "+" ? "" : c.d ? `+${c.d} ${n}` : n;
        lastEmitted.current = next;
        onChange(next);
    };

    const filtered = COUNTRIES.filter((c) => {
        const s = q.trim().toLowerCase();
        return !s || c.n.toLowerCase().includes(s) || c.d.includes(s.replace("+", ""));
    });

    return (
        <div className="relative" ref={ref}>
            <div className={`flex items-center border-b transition-colors ${error ? "border-red-500" : "border-gray-200"} focus-within:border-[#009688]`}>
                <button
                    type="button"
                    aria-label={country.d ? `Country code +${country.d}` : "International"}
                    onClick={() => setOpen((o) => !o)}
                    className="flex items-center gap-1.5 pr-2.5 py-3 text-[#282728] flex-shrink-0"
                >
                    <span className="text-lg leading-none">{country.iso ? flag(country.iso) : "🌐"}</span>
                    <span className="text-sm font-medium">{country.d ? `+${country.d}` : "International"}</span>
                    <ChevronDown size={14} className={`text-gray-400 transition-transform ${open ? "rotate-180" : ""}`} />
                </button>
                <input
                    type="tel"
                    inputMode="tel"
                    value={number}
                    onChange={(e) => {
                        // Parse an international paste before limiting digits. A
                        // native maxLength would truncate spaces and the prefix
                        // before this handler can recover the national number.
                        const raw = e.target.value.trim();
                        const digits = raw.replace(/\D/g, "");
                        const matched = raw.startsWith("+")
                            ? COUNTRY_PREFIXES.find((c) => digits.startsWith(c.d))
                            : null;
                        const selected = raw.startsWith("+") ? (matched || INTERNATIONAL) : country;
                        const national = matched ? digits.slice(matched.d.length) : digits;
                        const n = selected.d
                            ? national.slice(0, 15 - selected.d.length)
                            : (raw ? `+${digits.slice(0, 15)}` : "");
                        setCountry(selected);
                        setNumber(n);
                        emit(selected, n);
                    }}
                    placeholder={placeholder}
                    className="flex-1 min-w-0 bg-transparent py-3 text-[#282728] outline-none"
                />
            </div>

            {open && (
                <div className="absolute z-40 mt-1 w-72 max-w-[calc(100vw-3rem)] max-h-64 overflow-y-auto bg-white border border-gray-200 rounded-xl shadow-xl">
                    <div className="sticky top-0 bg-white p-2 border-b border-gray-100">
                        <div className="relative">
                            <Search size={14} className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" />
                            <input
                                autoFocus
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                placeholder="Search country or code…"
                                className="w-full pl-8 pr-2 py-1.5 text-sm rounded-lg border border-gray-200 outline-none focus:border-[#009688]"
                            />
                        </div>
                    </div>
                    {filtered.length === 0 ? (
                        <p className="px-3 py-4 text-sm text-gray-400 text-center">No match</p>
                    ) : filtered.map((c) => (
                        <button
                            key={c.iso}
                            type="button"
                            onClick={() => { const n = country.d ? number.slice(0, 15 - c.d.length) : ""; setCountry(c); setNumber(n); emit(c, n); setOpen(false); setQ(""); }}
                            className={`w-full flex items-center gap-2.5 px-3 py-2 text-left text-sm hover:bg-gray-50 ${c.iso === country.iso ? "bg-[#009688]/5" : ""}`}
                        >
                            <span className="text-lg leading-none">{flag(c.iso)}</span>
                            <span className="flex-1 truncate text-gray-700">{c.n}</span>
                            <span className="text-gray-400">+{c.d}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
