<h3>Consultation arrangement</h3>
<p>{{ \App\Services\Immigration\EngagementDocumentGenerator::consultationArrangement($consultation) }}</p>
<p>Date: {{ $consultation['consultation_date'] ?? 'Not yet supplied' }}<br>
Time: {{ $consultation['consultation_time'] ?? 'Not yet supplied' }} {{ $consultation['consultation_timezone'] ?? '' }}</p>
