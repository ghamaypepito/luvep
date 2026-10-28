@extends('agreements.engagement.service-layout')
@section('document')
<h1>Official Information Act (OIA) / Privacy Act Request Authorisation Letter</h1>
<h1>LETTER OF AUTHORISATION</h1>
<p>This letter confirms that I hereby authorise <strong>{{ $adviser['name'] }}, Licensed Immigration Adviser (IAA No. {{ $adviser['licence'] }}) AND D Immigration Consultancy Limited</strong>, to request and obtain information from Immigration New Zealand on my behalf under the Official Information Act 1982 and/or Privacy Act 2020.</p>
<h2>Information Requested</h2>
<p>I authorise the release of the following information and records held by Immigration New Zealand:</p>
<ul>
<li><strong>Complete file for All Previous NZ Visa applications</strong> - including the complete application form, all supporting documents and evidence submitted with the application, correspondence, immigration officer notes, assessment records, internal records, and any other documents or information relating to that application.</li>
<li><strong>AMS Notes</strong> - including all relevant Application Management System notes relating to the above application(s) and associated immigration matters.</li>
<li><strong>Application Information Report (AIR)</strong></li>
<li><strong>Client Information Report (CIR)</strong></li>
</ul>
<p>For the avoidance of doubt, this authorisation covers all records relevant to the above request, whether held electronically or otherwise, including attachments, internal notes, case notes, correspondence and records created by or provided to Immigration New Zealand.</p>
<h2>Applicant Details</h2>
<table class="details">
@foreach(['Full Name' => $client['name'], 'Date of Birth' => $client['dob'], 'Passport Number' => $client['passport'], 'Current Address' => $client['address'], 'Email Address' => $client['email'], 'Phone Number' => $client['phone']] as $label => $value)
<tr><td>{{ $label }}</td><td>{{ $value ?: 'Not yet supplied' }}</td></tr>
@endforeach
</table>
<div class="page-break"></div>
<h2>Declaration</h2>
<p>I confirm that the above authorisation is given voluntarily and remains valid until the requested information has been released by Immigration New Zealand or until revoked by me in writing.</p>
<p>Acknowledged and signed by the applicant:</p>
<p>Name: {{ $client['name'] }}</p>
<div class="signature"><img id="applicant-signature" src="{{ $client['signature'] ?? '' }}" alt="Applicant signature" style="display:{{ empty($client['signature']) ? 'none' : 'inline' }}"></div>
<p>Signature</p><p>Date: {{ !empty($client['signature']) ? ($client_signed_date ?? '________________') : '________________' }}</p>
<h2>Service Fee</h2>
<p>The service fee for the relevant file request is <strong>NZD $500.00 + GST</strong> (payable in advance).</p>
<p>The service includes submitting requests to Immigration New Zealand on behalf of the applicant to obtain the above-mentioned records, assisting the applicant in reviewing the relevant documents and information obtained, and providing visa-related advice based on the disclosed information.</p>
<p>Please use your own email address to send the completed and signed authorisation form back to us.</p>
<p>15 Mercari Way, Albany, Auckland 0632, New Zealand</p>
@endsection
