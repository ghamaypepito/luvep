import { useMemo, useState } from "react";
import { Head, Link, router } from "@inertiajs/react";
import {
    ArrowRight,
    CalendarDays,
    CheckCircle2,
    FileSignature,
    Printer,
    Search,
    TrendingUp,
    Users,
} from "lucide-react";

const colors = [
    "#2c6a4c",
    "#c1c5eb",
    "#c4d7ed",
    "#efd1e6",
    "#ecd78c",
    "#dfb4a5",
    "#b5d0b2",
];
const card = "rounded-[20px] border border-[#e6eae2] bg-white shadow-sm";
const control =
    "rounded-lg border border-[#dce4d9] bg-white px-3 py-2 text-sm text-[#192821] focus:outline-none focus:ring-2 focus:ring-[#386947]";
const dateValue = (value) => new Date(`${value}T00:00:00Z`);
const dateLabel = (value) =>
    new Intl.DateTimeFormat("en-NZ", {
        day: "numeric",
        month: "short",
        year: "numeric",
        timeZone: "UTC",
    }).format(dateValue(value));
const percent = (converted, count) =>
    count
        ? new Intl.NumberFormat("en-NZ", {
              style: "percent",
              maximumFractionDigits: 1,
          }).format(converted / count)
        : "—";
const initials = (name = "") =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join("") || "?";

function chartPoints(daily, from, to, grouping) {
    const dailyCounts = new Map(daily.map((row) => [row.date, row.count]));
    const buckets = new Map();
    const end = dateValue(to);
    for (
        let day = dateValue(from);
        day <= end;
        day.setUTCDate(day.getUTCDate() + 1)
    ) {
        const current = day.toISOString().slice(0, 10);
        const start = new Date(day);
        if (grouping === "Monthly") start.setUTCDate(1);
        if (grouping === "Weekly")
            start.setUTCDate(
                start.getUTCDate() - ((start.getUTCDay() + 6) % 7),
            );
        const key = start.toISOString().slice(0, 10);
        buckets.set(
            key,
            (buckets.get(key) || 0) + (dailyCounts.get(current) || 0),
        );
    }
    return Array.from(buckets, ([date, count]) => ({ date, count }));
}

function Trend({ daily, from, to }) {
    const [grouping, setGrouping] = useState(
        (dateValue(to) - dateValue(from)) / 86400000 > 90 ? "Monthly" : "Daily",
    );
    const points = useMemo(
        () => chartPoints(daily, from, to, grouping),
        [daily, from, to, grouping],
    );
    const peak = Math.max(...points.map((point) => point.count), 1);
    const ceiling = Math.ceil(peak / 4) * 4;
    const label = (value) =>
        new Intl.DateTimeFormat("en-NZ", {
            day: grouping === "Monthly" ? undefined : "numeric",
            month: "short",
            year: grouping === "Monthly" ? "2-digit" : undefined,
            timeZone: "UTC",
        }).format(dateValue(value));
    return (
        <section className={`${card} p-5`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold tracking-tight">
                        Lead generation
                    </h2>
                    <p className="mt-1 text-xs text-[#607069]">
                        Counted by referral creation date
                    </p>
                </div>
                <div
                    className="flex rounded-lg bg-[#f2f4ef] p-1 print:hidden"
                    role="group"
                    aria-label="Group lead counts"
                >
                    {["Daily", "Weekly", "Monthly"].map((value) => (
                        <button
                            key={value}
                            type="button"
                            aria-pressed={grouping === value}
                            onClick={() => setGrouping(value)}
                            className={`rounded-md px-2 py-1 text-xs font-semibold ${grouping === value ? "bg-white text-[#194d42] shadow-sm" : "text-[#607069]"}`}
                        >
                            {value}
                        </button>
                    ))}
                </div>
            </div>
            <div className="mt-6 grid grid-cols-[24px_1fr] gap-2">
                <div
                    className="flex h-44 flex-col justify-between text-[10px] text-[#607069]"
                    aria-hidden="true"
                >
                    {[4, 3, 2, 1, 0].map((step) => (
                        <span key={step}>{(step * ceiling) / 4}</span>
                    ))}
                </div>
                <div>
                    <div
                        className="flex h-44 items-end gap-1 border-b border-[#e6eae2] bg-[repeating-linear-gradient(to_top,#e6eae2_0,#e6eae2_1px,transparent_1px,transparent_25%)] px-1"
                        role="list"
                        aria-label={`${grouping} referral counts`}
                    >
                        {points.map((point) => (
                            <div
                                key={point.date}
                                role="listitem"
                                aria-label={`${grouping === "Weekly" ? "Week of " : ""}${dateLabel(point.date)}: ${point.count} leads`}
                                title={`${label(point.date)}: ${point.count} leads`}
                                style={{
                                    height: `${(point.count / ceiling) * 100}%`,
                                }}
                                className={`min-w-0 flex-1 rounded-t-md ${point.count === peak ? "bg-[#2c6a4c]" : "bg-[#e5ecdf]"}`}
                            />
                        ))}
                    </div>
                    <div
                        className="mt-2 flex justify-between gap-2 text-[10px] text-[#607069]"
                        aria-hidden="true"
                    >
                        {points
                            .filter(
                                (_, index) =>
                                    index === 0 ||
                                    index === points.length - 1 ||
                                    index === Math.floor(points.length / 2),
                            )
                            .map((point) => (
                                <span key={point.date}>
                                    {label(point.date)}
                                </span>
                            ))}
                    </div>
                </div>
            </div>
            <p className="mt-4 flex items-center gap-2 text-xs text-[#607069]">
                <span className="h-2 w-2 rounded-sm bg-[#2c6a4c]" />
                Referred leads
            </p>
        </section>
    );
}

function StatusBreakdown({ counts, leads, converted }) {
    let offset = 0;
    const gradient = counts
        .map((row, index) => {
            const start = offset;
            offset += leads ? (row.count / leads) * 100 : 0;
            return `${colors[index % colors.length]} ${start}% ${offset}%`;
        })
        .join(", ");
    return (
        <section className={`${card} p-5`}>
            <h2 className="text-xl font-semibold tracking-tight">
                Lead status
            </h2>
            <p className="mt-1 text-xs text-[#607069]">
                Current status of referrals in this period
            </p>
            <div className="my-4 flex items-center gap-5">
                <div
                    className="grid h-24 w-24 shrink-0 place-items-center rounded-full"
                    style={{
                        background: leads
                            ? `conic-gradient(${gradient})`
                            : "#edf1e9",
                    }}
                    aria-hidden="true"
                >
                    <div className="grid h-[70px] w-[70px] place-content-center rounded-full bg-white text-center">
                        <strong className="text-2xl font-semibold tracking-tight">
                            {leads}
                        </strong>
                        <span className="text-[10px] text-[#607069]">
                            referrals
                        </span>
                    </div>
                </div>
                <p className="text-xs leading-relaxed text-[#607069]">
                    <strong className="text-[#192821]">
                        {percent(converted, leads)}
                    </strong>{" "}
                    of referrals in this period are currently converted.
                </p>
            </div>
            {counts.length ? (
                <ul className="max-h-40 space-y-2 overflow-y-auto pr-1 print:max-h-none print:overflow-visible">
                    {counts.map((row, index) => (
                        <li
                            key={row.status}
                            className="grid grid-cols-[minmax(0,1.4fr)_1fr_30px] items-center gap-2 text-xs"
                        >
                            <span className="flex items-center gap-2">
                                <i
                                    className="h-2 w-2 shrink-0 rounded-full"
                                    style={{
                                        background:
                                            colors[index % colors.length],
                                    }}
                                    aria-hidden="true"
                                />
                                {row.status}
                            </span>
                            <span
                                className="h-1.5 overflow-hidden rounded-full bg-[#f0f2ef]"
                                aria-hidden="true"
                            >
                                <span
                                    className="block h-full rounded-full"
                                    style={{
                                        width: `${(row.count / leads) * 100}%`,
                                        background:
                                            colors[index % colors.length],
                                    }}
                                />
                            </span>
                            <strong className="text-right tabular-nums">
                                {row.count}
                            </strong>
                        </li>
                    ))}
                </ul>
            ) : (
                <p className="text-sm text-[#607069]">
                    No referrals in the selected dates.
                </p>
            )}
        </section>
    );
}

export default function AgentsIndex({
    agents = [],
    summary = { registered_partners: 0, leads: 0, converted: 0 },
    filters,
    daily = [],
    statusCounts = [],
    timezone = "UTC",
}) {
    const [draft, setDraft] = useState(filters);
    const [search, setSearch] = useState("");
    const selectedPartner = agents.find(
        (agent) => String(agent.id) === String(filters.agent_id),
    );
    const visible = agents.filter(
        (agent) =>
            (!filters.agent_id ||
                String(agent.id) === String(filters.agent_id)) &&
            [agent.name, agent.email, agent.location, agent.referral_code].some(
                (value) =>
                    (value || "")
                        .toLowerCase()
                        .includes(search.trim().toLowerCase()),
            ),
    );
    const metrics = [
        [
            "Registered partners",
            summary.registered_partners,
            "Across the partner network",
            Users,
            "bg-[#e7efdc] text-[#40753b]",
        ],
        [
            "Referred leads",
            summary.leads,
            "Created in the selected dates",
            CalendarDays,
            "bg-[#e3e9fa] text-[#657db1]",
        ],
        [
            "Converted leads",
            summary.converted,
            "From referrals in these dates",
            CheckCircle2,
            "bg-[#f3e0ed] text-[#a45b8c]",
        ],
        [
            "Conversion rate",
            percent(summary.converted, summary.leads),
            "Converted ÷ referred leads",
            TrendingUp,
            "bg-[#f0f6ca] text-[#72833b]",
        ],
    ];
    const submit = (event) => {
        event.preventDefault();
        router.get("/admin/agents", draft, { preserveScroll: true });
    };
    return (
        <div className="affiliate-page affiliate-report mx-auto max-w-[1450px] space-y-5 text-[#192821]">
            <Head title="Affiliate Partners" />
            <a href="/portal/education/partner-tracker" className="mb-4 inline-block rounded-lg border px-3 py-2 text-sm font-semibold">Partner Activity Tracker</a>
            <style>{`@media print { body * { visibility: hidden; } .affiliate-report, .affiliate-report * { visibility: visible; } .affiliate-report { position: absolute; inset: 0; width: 100%; max-width: none; padding: 16px; background: white; } .affiliate-report .print-hidden, .affiliate-report .print-hidden * { display: none !important; } .affiliate-report .overflow-x-auto { overflow: visible; } .affiliate-report table { font-size: 10px; } .affiliate-report * { print-color-adjust: exact; -webkit-print-color-adjust: exact; } }`}</style>
            <div className="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 className="text-3xl font-semibold tracking-tight sm:text-[40px]">
                        Affiliate Partners
                    </h1>
                    <p className="mt-2 text-sm text-[#607069]">
                        Monitor referrals, conversions and agreements across
                        your partner network.
                    </p>
                </div>
                <div className="print-hidden flex flex-wrap gap-2">
                    <Link
                        href="/admin/agents/agreements"
                        className={`${control} inline-flex items-center gap-2 font-semibold`}
                    >
                        <FileSignature size={15} />
                        Agreements
                    </Link>
                    <button
                        type="button"
                        onClick={() => window.print()}
                        className={`${control} inline-flex items-center gap-2 font-semibold`}
                    >
                        <Printer size={15} />
                        Print / Save PDF
                    </button>
                </div>
            </div>
            <form
                className="print-hidden flex flex-wrap items-end gap-2"
                onSubmit={submit}
            >
                <label className="grid gap-1 text-xs font-medium text-[#607069]">
                    Affiliate partner
                    <select
                        aria-label="Affiliate partner"
                        className={control}
                        value={draft.agent_id}
                        onChange={(event) =>
                            setDraft({ ...draft, agent_id: event.target.value })
                        }
                    >
                        <option value="">All affiliate partners</option>
                        {agents.map((agent) => (
                            <option key={agent.id} value={agent.id}>
                                {agent.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-medium text-[#607069]">
                    Account status
                    <select aria-label="Account status" className={control} value={draft.account_state || ""}
                        onChange={event => setDraft({ ...draft, account_state: event.target.value, agent_id: "" })}>
                        <option value="">All current accounts</option>
                        <option value="active">Active</option>
                        <option value="awaiting_signatures">Awaiting signatures</option>
                        <option value="disabled">Disabled</option>
                        <option value="archived">Archived</option>
                    </select>
                </label>
                <label className="grid gap-1 text-xs font-medium text-[#607069]">
                    From
                    <input
                        type="date"
                        required
                        className={control}
                        value={draft.from}
                        onChange={(event) =>
                            setDraft({ ...draft, from: event.target.value })
                        }
                    />
                </label>
                <label className="grid gap-1 text-xs font-medium text-[#607069]">
                    To
                    <input
                        type="date"
                        required
                        min={draft.from}
                        className={control}
                        value={draft.to}
                        onChange={(event) =>
                            setDraft({ ...draft, to: event.target.value })
                        }
                    />
                </label>
                <button className="rounded-lg bg-[#194d42] px-4 py-2 text-sm font-semibold text-white hover:bg-[#123c32]">
                    Apply filters
                </button>
                <Link href="/admin/agents" className={control}>
                    Reset
                </Link>
            </form>
            <p className="text-xs text-[#607069]">
                {selectedPartner?.name || "All affiliate partners"} ·{" "}
                {dateLabel(filters.from)} – {dateLabel(filters.to)} · {timezone}
            </p>
            <div
                className={`${card} grid grid-cols-2 gap-y-5 py-5 lg:grid-cols-4`}
            >
                {metrics.map(([label, value, note, Icon, tone], index) => (
                    <div
                        key={label}
                        className={`px-5 ${index < 3 ? "lg:border-r lg:border-[#e6eae2]" : ""}`}
                    >
                        <div className="mb-2 flex items-center justify-between gap-2 text-xs text-[#526257]">
                            {label}
                            <span className={`rounded-lg p-2 ${tone}`}>
                                <Icon size={16} />
                            </span>
                        </div>
                        <strong className="text-3xl font-semibold tracking-tight tabular-nums">
                            {value}
                        </strong>
                        <p className="mt-1 text-[11px] text-[#607069]">
                            {note}
                        </p>
                    </div>
                ))}
            </div>
            <div className="grid gap-5 xl:grid-cols-[1.55fr_1fr]">
                <Trend
                    key={`${filters.from}-${filters.to}-${filters.agent_id}`}
                    daily={daily}
                    from={filters.from}
                    to={filters.to}
                />
                <StatusBreakdown
                    counts={statusCounts}
                    leads={summary.leads}
                    converted={summary.converted}
                />
            </div>
            <section className={`${card} overflow-hidden`}>
                <header className="flex flex-wrap items-center justify-between gap-4 p-5">
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">
                            Partner performance
                        </h2>
                        <p className="mt-1 text-xs text-[#607069]">
                            Current outcomes for leads referred in the selected
                            dates
                        </p>
                    </div>
                    <label className="print-hidden relative">
                        <Search
                            className="absolute left-3 top-2.5 text-[#607069]"
                            size={16}
                        />
                        <input
                            aria-label="Find a partner"
                            type="search"
                            placeholder="Name, email or location"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            className={`${control} pl-9`}
                        />
                    </label>
                </header>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-xs">
                        <thead className="bg-[#f6f8f3] text-[#607069]">
                            <tr>
                                {[
                                    "Affiliate partner",
                                    "Contact",
                                    "Account status",
                                    "Location",
                                    "Leads",
                                    "Converted",
                                    "Rate",
                                    "Current statuses",
                                    "Agreement",
                                    "",
                                ].map((label) => (
                                    <th
                                        key={label}
                                        scope="col"
                                        className="px-4 py-3 font-semibold"
                                    >
                                        {label}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-[#edf1ea]">
                            {visible.length ? (
                                visible.map((agent) => (
                                    <tr
                                        key={agent.id}
                                        className="hover:bg-[#fafbf8]"
                                    >
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2">
                                                <span className="grid h-8 w-8 shrink-0 place-items-center overflow-hidden rounded-full bg-[#e7efe5] font-bold text-[#315842]">
                                                    {agent.avatar_url ? (
                                                        <img
                                                            src={
                                                                agent.avatar_url
                                                            }
                                                            alt=""
                                                            className="h-full w-full object-cover"
                                                        />
                                                    ) : (
                                                        initials(agent.name)
                                                    )}
                                                </span>
                                                <div>
                                                    <Link
                                                        href={`/admin/agents/${agent.id}`}
                                                        className="font-semibold hover:underline"
                                                    >
                                                        {agent.name}
                                                    </Link>
                                                    <small className="mt-0.5 block text-[10px] text-[#607069]">
                                                        {agent.referral_code ||
                                                            "No referral link yet"}
                                                    </small>
                                                </div>
                                            </div>
                                        </td>
                                        <td className="px-4 py-3 text-[#607069]">
                                            <span className="block">
                                                {agent.email || "—"}
                                            </span>
                                            <span className="block">
                                                {agent.phone || "—"}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-[#607069]">
                                            <span className="capitalize">{agent.account_state?.replace("_", " ") || "—"}</span>
                                        </td>
                                        <td className="px-4 py-3 text-[#607069]">
                                            {agent.location || "—"}
                                        </td>
                                        <td className="px-4 py-3 font-semibold tabular-nums">
                                            {agent.leads_count}
                                        </td>
                                        <td className="px-4 py-3 tabular-nums">
                                            {agent.converted_count}
                                        </td>
                                        <td className="px-4 py-3 font-semibold tabular-nums">
                                            {percent(
                                                agent.converted_count,
                                                agent.leads_count,
                                            )}
                                        </td>
                                        <td className="min-w-40 px-4 py-3">
                                            <details className="print:hidden">
                                                <summary className="cursor-pointer text-[#386947]">
                                                    {agent.status_counts.length}{" "}
                                                    statuses
                                                </summary>
                                                <ul className="mt-2 space-y-1 text-[#607069]">
                                                    {agent.status_counts.map(
                                                        (row) => (
                                                            <li
                                                                key={row.status}
                                                            >
                                                                {row.status}{" "}
                                                                <strong>
                                                                    {row.count}
                                                                </strong>
                                                            </li>
                                                        ),
                                                    )}
                                                </ul>
                                            </details>
                                            <p className="hidden print:block">
                                                {agent.status_counts
                                                    .map(
                                                        (row) =>
                                                            `${row.status}: ${row.count}`,
                                                    )
                                                    .join(" · ")}
                                            </p>
                                        </td>
                                        <td className="px-4 py-3">
                                            {agent.has_agreement ? (
                                                <span className="rounded-full bg-[#e3efe5] px-2 py-1 text-[11px] font-semibold text-[#236143]">
                                                    {agent.agreement_status || "On file"}
                                                </span>
                                            ) : (
                                                <span className="text-[#607069]">
                                                    None
                                                </span>
                                            )}
                                        </td>
                                        <td className="print-hidden whitespace-nowrap px-4 py-3">
                                            <Link
                                                href={`/admin/agents/${agent.id}`}
                                                className="inline-flex items-center gap-1 font-semibold text-[#285d46]"
                                            >
                                                View profile{" "}
                                                <ArrowRight size={13} />
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            ) : (
                                <tr>
                                    <td
                                        colSpan={10}
                                        className="p-10 text-center text-[#607069]"
                                    >
                                        No affiliate partners match your
                                        filters.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </section>
            <p className="text-xs leading-relaxed text-[#607069]">
                Each lead counts once. A conversion is a lead marked Converted
                or added to a student, English, Immigration or Accommodation
                pathway. Current statuses can change as referrals progress.
            </p>
        </div>
    );
}
