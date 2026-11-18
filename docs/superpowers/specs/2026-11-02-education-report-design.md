# Education report: approved period filters and insights

The user approved the local original-theme preview and explicitly requested live deployment. Preserve the four report sections, department tabs, cards, supporting lists, pipeline and commentary. Use 12px date/department filter labels. Do not redesign other portals in this release.

Six visible presets: Today, This Week, This Month, This Year, Custom, All. Default This Month. NZ calendar boundaries use Pacific/Auckland, Monday week start, current week/month/year to date. Custom requires valid non-reversed dates no later than the NZ current day. Legacy quarter/two_weeks/last_month URLs remain accepted as equivalent custom windows.

Dated department cards count distinct clients with a recorded matching department/stage event inside the period, retaining milestones after later progression. Department totals deduplicate clients across stages; raw stage-event counts are separately labelled. All uses current register membership/position, retaining unknown dates/stages and legacy status labels. Existing student-register and department membership rules remain authoritative. Missing or malformed history is unknown, never inferred from shared stage_updated_at or updated_at.

Add original-theme insights beneath the cards: metric trend, previous equal-calendar-length period, accessible bucket figures, most-reached stage, distinct active students and clearly separated current-register coverage warnings. Use daily buckets for <=31 days, weekly for <=186, otherwise at least 30-day buckets, bounded to 60 points. Choose each client's latest qualifying event per metric per period so bucket sums equal the metric count. All has no arbitrary previous-period comparison.

Pipeline All mirrors the current Leads queue. Dated pipeline counts only recorded lead status-change audit events, including earlier recorded milestones after conversion; do not infer initial historical stages from current status. Missing audit history is disclosed. Current catalogue totals appear only under All; dated program-change history is unavailable and displays dashes with an explanation.

All UI data comes from authenticated server props; no synthetic data or client-side history reconstruction in production. Existing Live refresh stays, preserving selected filters, department, metric and commentary draft. Report failure displays a visible error. Keep active internal staff with Education portal access; deny external lead/agent/sub-agent/revoked users even with a manually assigned portal grant. No new client emails, data backfill, schema changes or permissions grants.

Approach: extend the existing report using a dedicated period/value service and aggregation service. The standalone synthetic preview remains separate; its mock router and fixture generator will not ship. The user chose this extension over the earlier standalone redesigned dashboard.
