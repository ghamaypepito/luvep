<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Education Cost Breakdown</title>
<style>
@page { margin: 42px; }
body { font: 10pt/1.45 'DejaVu Sans', sans-serif; color: #18362c; }
h1 { font-size: 21pt; margin-bottom: 4px; }
.notice { padding: 10px; background: #edf4ef; margin: 14px 0; }
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
th,td { padding: 8px 5px; border-bottom: 1px solid #d4ded7; overflow-wrap: break-word; word-wrap: break-word; }
th { background: #244d3c; color: white; text-align: left; }
.num { text-align: right; } tfoot { font-weight: bold; background: #edf4ef; }
tr { page-break-inside: avoid; } td { vertical-align: top; } .totals { font-weight: bold; background: #edf4ef; } thead { display: table-header-group; }
.small { font-size: 8pt; color: #4c6357; } .description { width: 40%; }
</style></head><body>
<h1>Education Cost Breakdown</h1>
<p><strong>{{ $client }}</strong><br>{{ $country }} - {{ ucfirst($category) }}<br>
{{ ['single'=>'Single','couple'=>'Couple','couple_child'=>'Couple with child'][$family] }}</p>
<div class="notice">Indicative estimate only. Converted amounts are for reference and are not a payment demand or a change to your original agreed fees. Costs may vary by programme, provider and exchange rate.</div>
<table><thead><tr><th class="description">Description</th><th class="num">Original<br>{{ $source_currency }}</th><th class="num">Estimate<br>NZD</th><th class="num">Estimate<br>{{ $target_currency }}</th></tr></thead>
<tbody>@foreach($rows as $row)<tr><td>{{ $row['description'] }}</td><td class="num">{{ number_format($row['amount'],2) }}</td><td class="num">{{ number_format($row['nzd'],2) }}</td><td class="num">{{ number_format($row['local'],2) }}</td></tr>@endforeach
<tr class="totals"><td>Listed cost total</td><td class="num">{{ number_format(array_sum(array_column($rows,'amount')),2) }}</td><td class="num">{{ number_format(array_sum(array_column($rows,'nzd')),2) }}</td><td class="num">{{ number_format(array_sum(array_column($rows,'local')),2) }}</td></tr></tbody></table>
<p class="small">Totals sum the displayed rounded line amounts. All estimates use two decimal places. Only the costs listed above are included.</p>
<p class="small">Reference source: {{ $exchange['provider'] }} ({{ $exchange['provider_url'] }})<br>
@foreach($exchange['rates'] as $code=>$rate)
1 NZD = {{ number_format($rate['rate'],8,'.','') }} {{ $code }} - rate date {{ $rate['date'] }}<br>
@endforeach
Prepared {{ $generated_at }}. The source and rate snapshot remain fixed in this saved document.</p>
</body></html>
