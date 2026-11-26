<!doctype html>
<html><head><meta charset="UTF-8"><style>
@page{margin:35px 35px 45px}footer{position:fixed;bottom:-25px;left:0;right:0;font-size:8px;color:#536a63}.page:before{content:counter(page)}body{font-family:DejaVu Sans,sans-serif;font-size:9px;color:#172e29}h1{font-size:20px}h2{page-break-after:avoid;font-size:14px;margin-top:22px}h3{page-break-after:avoid;font-size:11px;margin-bottom:4px}p{margin:4px 0;word-wrap:break-word;white-space:pre-wrap}.activity{border-top:1px solid #bdd0c9;padding:8px 0}table{width:100%;border-collapse:collapse}td{padding:5px;border:1px solid #d8e2de}.muted{color:#536a63}
</style></head><body>
<footer>eP Partner Activity Report - Page <span class="page"></span></footer>
<h1>Agent & Affiliate Partner Activity Report</h1>
<p>{{ $filters['from'] }} through {{ $filters['to'] }} · Timezone: {{ $timezone }}</p>
<p class="muted">Activity rows use the selected dates. Agreement and pending/overdue cards show current workload for the selected partner/category/staff. Renewal window: {{ $filters['renewal_days'] }} days. Automatic rows retain their source records; historical state is not inferred from current status.</p>
<p>Partner: {{ $partners->firstWhere("id", $filters["agent_id"])["name"] ?? "All partners" }} · Category: {{ $filters["category"] ?: "All categories" }} · Staff: {{ $staff->firstWhere("id", $filters["assignee_id"])["name"] ?? "All staff" }}</p>
<table>@foreach($summary as $name => $value)<tr><td>{{ ucwords(str_replace('_', ' ', $name)) }}</td><td>{{ $value }}</td></tr>@endforeach</table>
@foreach(['Dated activities' => $rows, 'Agreement tracking — current state' => $agreements, 'Overdue workload — all dates' => $workload] as $section => $items)
<h2>{{ $section }}</h2>
@forelse($items as $row)
<div class="activity"><h3>{{ $row['partner'] }} · {{ $row['title'] }}</h3>
<p>{{ $row['activity_on'] }} · {{ ucwords(str_replace('_', ' ', $row['category'])) }} · {{ ucwords(str_replace('_', ' ', $row['status'])) }} · Staff: {{ $row['staff'] ?: 'Unassigned' }}</p>
<p>Follow-up: {{ $row['follow_up_on'] ?: 'Not set' }} · {{ $row['overdue'] ? 'Overdue' : 'Not overdue' }} · Source: {{ $row['source_key'] }}</p>
<p>Last updated: {{ $row['updated_at'] }}</p>
@foreach($row['details'] as $key => $value)
@if(is_scalar($value) && ! str_ends_with($key, '_url'))<p>{{ ucwords(str_replace('_', ' ', $key)) }}: {{ is_bool($value) ? ($value ? 'Yes' : 'No') : $value }}</p>@endif
@endforeach
</div>
@empty<p>No matching records.</p>@endforelse
@endforeach
</body></html>
