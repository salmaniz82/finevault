@php
$onboarding = $documents->where('data.category', 'Onboarding');
$acknowledgements = $records->where('kind', 'onboarding_ack')->pluck('data.document_id')->all();
@endphp
<section class="card" aria-label="Onboarding activities">
<div class="card-head"><div><p class="eyebrow copper">YOUR NEXT STEPS</p><h2>Onboarding & required documents</h2></div><span class="badge">{{ $onboarding->filter(fn($d) => in_array($d->id, $acknowledgements))->count() }} / {{ $onboarding->count() }} reviewed</span></div>
<div class="note-panel">Review the documents below and record that you have read them. Your portfolio team will confirm when you can proceed. This acknowledgement is not an electronic signature.</div>
@forelse($onboarding as $item)
<div class="document-row"><div class="file-copy"><h3>{{ $item->data['title'] }}</h3><p>{{ $item->data['document_date'] }} · {{ $item->data['notes'] ?? 'Please review before proceeding.' }}</p><a class="text-link" href="/documents/{{ $item->id }}/download" target="_blank" rel="noopener">View document ↓</a>
@if(in_array($item->id, $acknowledgements))<p><span class="badge approved">Review recorded</span></p>
@else<form method="post" action="/onboarding/{{ $item->id }}/acknowledge" style="margin-top:12px">@csrf<label class="checkbox"><input type="checkbox" name="acknowledged" value="1" required>I have read this document</label><button class="primary small-button">Record review</button></form>@endif
</div></div>
@empty<div class="empty">Your team has not published an onboarding document yet. Required documents will appear here when ready.</div>@endforelse
<div class="card-content"><a class="text-link" href="/documents">Upload onboarding documents & view review status →</a>
@foreach($submissions->take(3) as $submission)<p>{{ $submission->data['title'] }} <span class="badge">{{ ucfirst($submission->data['status']) }}</span>@if(!empty($submission->data['review_note']))<br><small>{{ $submission->data['review_note'] }}</small>@endif</p>@endforeach
</div></section>
