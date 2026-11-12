@extends('layout')
@section('content')
<div class="page-head"><div><h1>{{ $client->name }} · Onboarding</h1><p>Complete the checklist, then submit for admin review.</p></div><span class="badge">{{ ucfirst($profile->onboarding_status) }}</span></div>
@if($profile->review_note)<div class="notice">{{ $profile->review_note }}</div>@endif
<div class="detail-grid"><div>
<section class="card card-content"><h2>1. Verify email</h2><p>{{ $client->email }} · {{ $client->hasVerifiedEmail() ? 'Verified' : 'Not verified' }}</p>
@if(auth()->id() === $client->id && !$client->hasVerifiedEmail())<form method="post" action="/email/verification-notification">@csrf<button class="secondary">Send verification email</button></form>@endif</section>
<section class="card card-content"><h2>2. Personal details</h2><form method="post" action="/onboarding/{{ $client->id }}/details">@csrf
<fieldset @disabled(in_array($profile->onboarding_status,['submitted','approved']))><div class="form-grid">@foreach(config('onboarding.personal_fields') as $key=>$label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$key === 'name' ? $client->name : $profile->$key) }}" required maxlength="{{ $key === 'address' ? 1000 : ($key === 'phone' ? 60 : 100) }}"></label>@endforeach</div><button class="primary">Save details</button></fieldset></form></section>
<section class="card card-content"><h2>3. Identity and address proof</h2><p>Provide one identity document and one address-proof document. Files remain private to authorised staff.</p>
@foreach($submissions as $document)<div class="document-row"><div><strong>{{ config('onboarding.document_purposes.'.$document->data['onboarding_purpose']) }}</strong><p>{{ $document->data['title'] }} · Uploaded {{ $document->created_at->diffForHumans() }}</p></div>@if(auth()->user()->role !== 'client')<a class="text-link" href="/documents/{{ $document->id }}/download">Review file</a>@endif</div>@endforeach
@unless(in_array($profile->onboarding_status,['submitted','approved']))<form method="post" action="/onboarding/{{ $client->id }}/documents" enctype="multipart/form-data">@csrf<div class="form-grid"><label>Purpose<select name="purpose">@foreach(config('onboarding.document_purposes') as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label><label>Document type<select name="label">@foreach(config('onboarding.document_labels') as $label)<option>{{ $label }}</option>@endforeach</select></label><label>File · PDF/JPG/PNG, up to 10 MB<input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png"></label></div><button class="secondary">Add document</button></form>@endunless</section>
@if(auth()->user()->role !== 'client')
<section class="card"><div class="card-head"><h2>Earlier client records</h2></div>
@foreach(\App\Models\PortalEntry::where('client_id',$client->id)->where('kind','document')->whereHas('documentVersion',fn($q)=>$q->whereNull('holding_id')->whereNull('deal_id')->whereNull('onboarding_purpose'))->get() as $legacy)
@if(($legacy->data['category'] ?? '') !== 'Agreement')<div class="document-row"><div class="file-copy"><strong>{{ $legacy->data['title'] }}</strong><p>{{ $legacy->data['category'] }} · {{ $legacy->status }}</p></div><a class="text-link" href="/documents/{{ $legacy->id }}/download">Review file</a></div>@endif
@endforeach
</section>@endif
</div><div><section class="card card-content"><h2>4. Admin review</h2><p>Agreement access is enabled after admin approval. Holdings require their signed agreement and admin release.</p>
@if(in_array($profile->onboarding_status,['draft','returned']))<form method="post" action="/onboarding/{{ $client->id }}/submit">@csrf<button class="primary">Submit onboarding</button></form>
@elseif($profile->onboarding_status === 'submitted')<p>Waiting for admin review. Details are locked while under review.</p>@endif
@if(auth()->user()->role === 'admin' && $profile->onboarding_status === 'submitted')<form method="post" action="/onboarding/{{ $client->id }}/review">@csrf<label>Review note<input name="reason" maxlength="1000"></label><div class="form-actions"><button class="secondary" name="decision" value="returned">Return for correction</button><button class="primary" name="decision" value="approved">Approve onboarding</button></div></form>@endif
@if($profile->onboarding_status === 'approved')<a class="text-link" href="{{ auth()->user()->role === 'client' ? '/deals' : '/client/'.$client->id }}">Continue →</a>@endif
</section></div></div>
@endsection
