@extends('agreements.engagement.service-layout')
@section('document')
<h1>INITIAL CONSULTATION AGREEMENT</h1>
<p>This Initial Consultation Agreement ("Agreement") is entered into between the undersigned consultant ("Consultant") and the undersigned client ("Client") in connection with an initial visa consultation session, subject to the terms below.</p>
<h2>1. Consultation Details</h2>
<table class="details">
@foreach([
    'Client Name' => $client['name'],
    'Consultation Date' => !empty($consultation['consultation_date']) ? \Carbon\Carbon::parse($consultation['consultation_date'])->format('d/m/Y') : 'Not yet supplied',
    'Consultation Time' => trim(($consultation['consultation_time'] ?? '').' '.($consultation['consultation_timezone'] ?? '')) ?: 'Not yet supplied',
    'Platform' => 'Google Meet', 'Meeting Link' => $consultation['meeting_link'] ?? 'Not yet supplied',
    'Consultant Name' => $adviser['name'],
] as $label => $value)
<tr><td>{{ $label }}</td><td>{{ $value }}</td></tr>
@endforeach
</table>
<h2>2. Scope of Consultation</h2>
<p>The initial consultation is intended to provide the Client with a general assessment of their visa options, eligibility, and next steps. It does not constitute the filing or processing of any visa application, and no representation, petition, or submission will be made to any government agency during this session.</p>
<h2>3. Booking Fee</h2>
<ul>
<li>A non-refundable booking fee of <strong>$250.00 (USD)</strong> is required to reserve and confirm the consultation slot.</li>
<li>The booking fee secures the date and time listed above via Google Meet.</li>
<li>Should the Client decide to proceed with visa processing following the consultation, the $250.00 booking fee already paid will be credited/deducted in full toward the total consulting and service fee for the visa processing engagement.</li>
<li>If the Client does not proceed with visa processing, the booking fee is retained by the Consultant as payment for the consultation session rendered and is non-refundable.</li>
</ul>
<h2>4. Visa Processing &amp; Service Fees</h2>
<p>Should the Client engage the Consultant for visa processing services following this consultation, a separate Visa Processing / Service Agreement will be issued outlining the full scope of services, applicable government/filing fees, and the total consulting and service fee, less the $250.00 booking fee already collected.</p>
<div class="page-break"></div>
<h2>5. Cancellation &amp; Rescheduling</h2>
<ul><li>Rescheduling requests must be made at least 24 hours before the scheduled consultation time.</li><li>No-shows or cancellations made less than 24 hours in advance may forfeit the booking fee.</li></ul>
<h2>6. Confidentiality</h2>
<p>Both parties agree that any information disclosed during the consultation shall be kept confidential and used solely for the purpose of assessing the Client's visa options.</p>
<h2>7. Acknowledgment &amp; Agreement</h2>
<p>By signing below, the Client acknowledges having read, understood, and agreed to the terms of this Initial Consultation Agreement, including the $250.00 booking fee and its conditions as stated above.</p>
<table class="signatures"><tr><td>
<div class="signature"><img id="applicant-signature" src="{{ $client['signature'] ?? '' }}" alt="Client signature" style="display:{{ empty($client['signature']) ? 'none' : 'inline' }}"></div>
Client Signature<br>Printed Name: {{ $client['name'] }}<br>Date: {{ !empty($client['signature']) ? ($client_signed_date ?? '________________') : '________________' }}
</td><td>
<div class="signature">@if(!empty($adviser['signature']))<img src="{{ $adviser['signature'] }}" alt="Consultant signature">@endif</div>
Consultant Signature<br>Printed Name: {{ $adviser['name'] }}<br>Date: {{ $generated_date }}
</td></tr></table>
@endsection
