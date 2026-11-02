import { useState } from "react";
import { BarChart3, ChevronDown, Info } from "lucide-react";

const dateLabel = (value) => value ? new Date(`${value.slice(0, 10)}T12:00:00Z`).toLocaleDateString("en-NZ", { day: "numeric", month: "short", year: "numeric", timeZone: "UTC" }) : "—";
const dates = (from, to) => from === to ? dateLabel(from) : `${dateLabel(from)} – ${dateLabel(to)}`;

// Counts and buckets are authoritative server values. History is never rebuilt in the browser.
export default function EducationReportInsights({ insights = {}, department = "Education", range = {} }) {
    const [expanded, setExpanded] = useState(true);
    const [selectedMetric, setSelectedMetric] = useState("");
    const metrics = insights.metrics || [];
    const metric = metrics.find((item) => item.key === selectedMetric) || metrics[0];
    const points = metric?.points || [];
    const compare = !range.all && range.compare !== false;
    const bucketLabel = range.bucket_label || (range.bucket_days === 1 ? "Daily" : range.bucket_days === 7 ? "Weekly" : `${range.bucket_days || 30}-day`);
    const ceiling = Math.max(4, Math.ceil(Math.max(0, ...points.flatMap((point) => [point.count, compare ? point.previous_count || 0 : 0])) / 4) * 4);
    const x = (index) => points.length === 1 ? 267 : 38 + index / Math.max(1, points.length - 1) * 462;
    const y = (value) => 151 - value / ceiling * 123;
    const largest = insights.largest;
    const currentCount = metric?.count ?? 0;

    return (
        <div className="mt-4">
            <button type="button" onClick={() => setExpanded(!expanded)} aria-expanded={expanded} className="w-full flex items-center justify-between gap-3 py-2 text-[13px] font-bold text-gray-800 focus-visible:outline-2 focus-visible:outline-blue-600 focus-visible:outline-offset-2">
                <span className="inline-flex items-center gap-2"><BarChart3 size={16} className="text-blue-600" />Additional insights</span>
                <ChevronDown size={16} className={expanded ? "rotate-180" : ""} />
            </button>
            {expanded && <>
                <p className="flex items-start gap-2 mt-1 mb-3 text-[11px] leading-relaxed text-gray-500"><Info size={14} className="shrink-0 mt-0.5" /><span>{range.all ? "All shows current records in the cards above. The trend uses all available dated history; missing history stays unknown." : "Cards, supporting lists and insights follow the selected dates. Counts use recorded department-specific stage history."}</span></p>
                <div className="grid grid-cols-[minmax(0,1fr)] lg:grid-cols-[minmax(0,1.5fr)_minmax(260px,1fr)] gap-3">
                    <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5 min-w-0" aria-label={`${department} trend`}>
                        <div className="flex flex-wrap items-start justify-between gap-3 mb-3">
                            <div><h3 className="text-[13px] font-bold text-gray-900">Recorded stage entries over time</h3><p className="text-[11px] text-gray-500 mt-1">{range.all ? "All recorded history" : dates(range.from, range.to)} · {department}</p></div>
                            {metrics.length > 0 && <select aria-label="Insight metric" value={metric?.key || ""} onChange={(event) => setSelectedMetric(event.target.value)} className="max-w-full rounded-lg border border-gray-200 bg-white px-2 py-1.5 text-[12px] text-gray-700 focus-visible:outline-2 focus-visible:outline-blue-600">
                                {metrics.map((item) => <option key={item.key} value={item.key}>{item.label}</option>)}
                            </select>}
                        </div>
                        {!metric ? <p className="rounded-lg bg-blue-50 p-3 text-[12px] text-blue-800">No recorded stage history is available for this department.</p> : <>
                            <div className="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-gray-600 mb-2">
                                <span className="inline-flex items-center gap-1.5"><span className="w-4 border-t-2 border-blue-600" />{range.all ? "All recorded history" : "Selected period"} · <strong>{currentCount}</strong></span>
                                {compare && <span className="inline-flex items-center gap-1.5"><span className="w-4 border-t-2 border-dashed border-violet-500" />Previous {range.days} {range.days === 1 ? "day" : "days"} · <strong>{metric.previous_count ?? 0}</strong></span>}
                            </div>
                            {currentCount === 0 && <p className="rounded-lg bg-blue-50 px-3 py-2 text-[11px] text-blue-800 mb-2">No recorded {metric.label.toLowerCase()} entries {range.all ? "in available history" : "during the selected dates"}.</p>}
                            {points.length > 0 ? <svg viewBox="0 0 535 200" className="w-full h-auto" role="img" aria-label={`${metric.label}: ${currentCount} distinct students in ${range.all ? "all recorded history" : compare ? `the selected period, ${metric.previous_count ?? 0} in the previous equal-length period` : "the selected period"}. Bucket values are in the figures table.`}>
                                {[0, 1, 2, 3, 4].map((step) => <g key={step}><line x1="38" y1={y(step * ceiling / 4)} x2="500" y2={y(step * ceiling / 4)} stroke="#e5e7eb" /><text x="30" y={y(step * ceiling / 4) + 4} textAnchor="end" fontSize="11" fill="#6b7280">{step * ceiling / 4}</text></g>)}
                                {compare && <polyline points={points.map((point, index) => `${x(index)},${y(point.previous_count || 0)}`).join(" ")} stroke="#8b5cf6" strokeWidth="2" strokeDasharray="5 4" fill="none" />}
                                {compare && points.length === 1 && <circle cx={x(0)} cy={y(points[0].previous_count || 0)} r="3" fill="#8b5cf6" />}
                                <polyline points={points.map((point, index) => `${x(index)},${y(point.count)}`).join(" ")} stroke="#2563eb" strokeWidth="2.5" fill="none" strokeLinejoin="round" />
                                {points.map((point, index) => <circle key={`${point.from}-${index}`} cx={x(index)} cy={y(point.count)} r="3" fill="#2563eb"><title>{`${dates(point.from, point.to)}: ${point.count}`}</title></circle>)}
                                {[...new Set([0, Math.floor((points.length - 1) / 2), points.length - 1])].map((index) => <text key={index} x={x(index)} y="180" textAnchor={points.length === 1 ? "middle" : index === 0 ? "start" : index === points.length - 1 ? "end" : "middle"} fontSize="10" fill="#6b7280">{dateLabel(points[index].from)}</text>)}
                            </svg> : <p className="text-[12px] text-gray-500 py-4">No dated entries are available to plot.</p>}
                            <details className="mt-2 mb-3 text-[11px] text-blue-700">
                                <summary className="cursor-pointer underline underline-offset-2">{bucketLabel} figures</summary>
                                <div className="max-h-52 overflow-auto mt-2">
                                    <table className="w-full text-left text-[11px] text-gray-600 border-collapse">
                                        <caption className="sr-only">{department} · {metric.label} · distinct students by bucket</caption>
                                        <thead><tr><th scope="col" className="p-2 border-b border-gray-100">Selected dates</th><th scope="col" className="p-2 border-b border-gray-100">Students</th>{compare && <><th scope="col" className="p-2 border-b border-gray-100">Previous dates</th><th scope="col" className="p-2 border-b border-gray-100">Students</th></>}</tr></thead>
                                        <tbody>{points.map((point, index) => <tr key={`${point.from}-${index}`}><th scope="row" className="p-2 border-b border-gray-100 font-normal">{dates(point.from, point.to)}</th><td className="p-2 border-b border-gray-100 tabular-nums">{point.count}</td>{compare && <><td className="p-2 border-b border-gray-100">{dates(point.previous_from, point.previous_to)}</td><td className="p-2 border-b border-gray-100 tabular-nums">{point.previous_count ?? 0}</td></>}</tr>)}</tbody>
                                    </table>
                                </div>
                            </details>
                            <p className="text-[11px] leading-relaxed text-gray-500">Each student counts once per metric in each period. {range.bucket_days === 1 ? "Daily points." : `${range.bucket_days || 30}-day buckets; the final bucket may be shorter.`} Recorded {department.toLowerCase()} history only.</p>
                        </>}
                    </section>
                    <section className="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 sm:p-5 min-w-0" aria-label={`${department} activity insights`}>
                        <h3 className="text-[13px] font-bold text-gray-900">{range.all ? "Recorded-history insights" : "Insights for selected period"}</h3>
                        <p className="text-[11px] text-gray-500 mt-1">{range.all ? "All recorded history" : dates(range.from, range.to)} · {department}</p>
                        <div className="py-4 border-b border-gray-100">
                            <p className="text-[11px] text-gray-500 mb-2">Most reached stage {range.all ? "in recorded history" : "during these dates"}</p>
                            {largest ? <div className="flex items-center gap-3"><strong className="text-3xl font-black text-gray-900 tabular-nums">{largest.count}</strong><div className="text-[12px] font-bold text-gray-700">{largest.stage}<p className="text-[11px] font-normal text-gray-500 mt-1">{largest.share}% of students with recorded activity</p></div></div> : <p className="text-[12px] text-gray-600">No recorded stage activity.</p>}
                        </div>
                        <div className="py-4 border-b border-gray-100">
                            <p className="text-[11px] text-gray-500 mb-2">Students with recorded stage activity</p>
                            <p className="text-[13px] font-bold text-gray-700">{insights.active_students ?? 0} distinct students</p>
                            <p className="text-[11px] leading-relaxed text-gray-500 mt-2">Students who progressed remain counted. Current department register: {insights.register_total ?? 0} students.</p>
                        </div>
                        <div className="flex gap-2 pt-4 text-[11px] leading-relaxed text-gray-500"><Info size={14} className="shrink-0 mt-0.5 text-blue-600" /><p><strong className="font-semibold text-gray-600">Current-register data check: {insights.missing_history ?? 0} records lack a dated current-stage entry; {insights.missing_stage ?? 0} have no current stage.</strong> These are snapshot coverage counts, independent of the selected dates. Missing history stays unknown and is excluded from dated activity.</p></div>
                    </section>
                </div>
                <p className="text-[11px] leading-relaxed text-gray-500 mt-3">{range.all ? "All cards show current position, including undated records. History charts describe only recorded events." : "Each card counts distinct students entering that stage in these dates. One student may appear across multiple cards, while the department total counts that student once."}</p>
            </>}
        </div>
    );
}
