@extends('layout')
@section('content')
<link rel="stylesheet" href="/direct-signing.css">
<div class="page-head"><div><p class="eyebrow copper">IN-PORTAL SIGNING · NO EXTERNAL SERVICE</p><h1>A signature, simply.</h1><p>Review the agreement and add your signature to its marked signing fields.</p></div><span class="badge {{ $completed ? 'approved' : 'pending' }}">{{ $completed ? 'Completed' : 'Awaiting signature' }}</span></div>
<div class="note-panel">Admin demonstration of the client experience · Alexander Morgan’s fictional agreement. This applies a signature image; it does not verify identity or create a certificate-based digital signature.</div>
@if($completed)
<section class="card card-content" style="margin-top:24px"><p class="eyebrow copper">SIGNATURE SAVED</p><h2>Your agreement is complete.</h2><p>Signed as {{ $completed->data['name'] }} · {{ $completed->data['signed_at'] }} UTC · {{ ucfirst($completed->data['method']) }} signature</p><a class="primary" href="/direct-signing-demo/{{ $completed->id }}/download">Download signed PDF ↓</a><form method="post" action="/direct-signing-demo/reset" style="margin-top:20px">@csrf<button class="secondary">Start another demonstration</button></form><p class="form-help">The original agreement and earlier signed demonstrations are retained. No live deal status has changed.</p></section>
@else
<form method="post" action="/direct-signing-demo/reset" class="card card-content" style="margin-top:20px">@csrf<label>Sample length <select name="variant"><option value="standard" @selected($variant === 'standard')>Standard agreement</option><option value="extended" @selected($variant === 'extended')>Extended agreement — extra clauses</option></select></label><button class="secondary">Load sample</button><p class="form-help">Compare how signing fields follow the final content. Loading a sample starts a new demonstration and clears the current signature.</p></form>
<form id="signature-form" method="post" action="/direct-signing-demo/save" data-hotspots="{{ json_encode($hotspots) }}">
@csrf<input type="hidden" name="token" value="{{ $token }}"><input type="hidden" name="signature" id="signature-data"><input type="hidden" name="source_sha256" value="{{ $hotspots['source_sha256'] }}">
<div class="sign-grid">
<section class="card"><div class="card-head"><h2>01 / Review & place</h2><a href="/direct-signing-demo/sample" target="_blank" rel="noopener" class="text-link">Open original ↗</a></div><div class="card-content">
<div class="sign-toolbar"><label>Page <select name="page" id="pdf-page" aria-label="PDF page"><option value="1">1</option></select></label><span id="pdf-status" role="status">Loading agreement…</span></div>
<p class="form-help">Highlighted fields follow the document’s signing block. Your signature, full name and date are placed automatically on page {{ $hotspots['fields']['signature']['page'] }}.</p><button type="button" class="secondary" id="jump-signing">Go to signing fields</button>
<div id="pdf-sheet"><span id="hotspot-signature" class="signing-hotspot" aria-label="Signature hotspot"></span><span id="hotspot-full_name" class="signing-hotspot" aria-label="Full name hotspot"></span><span id="hotspot-signed_date" class="signing-hotspot" aria-label="Signing date hotspot"></span><canvas id="pdf-canvas" aria-label="Sample agreement page"></canvas><span id="printed-name" class="pdf-filled-field"></span><span id="printed-date" class="pdf-filled-field" data-date="{{ \App\Services\DirectSignature::signingDate(now()->utc()->format('Y-m-d H:i:s')) }}"></span><img id="placed-signature" alt="Your signature placement" hidden draggable="false"></div>
</div></section>
<section class="card"><div class="card-head"><h2>02 / Your signature</h2></div><div class="card-content">
<label class="sign-label">Full name<input name="name" id="signer-name" maxlength="100" minlength="2" required value="{{ old('name', 'Alexander Morgan') }}" autocomplete="name"></label>
<p class="form-help">Your name fills the client-name hotspot. The signing date is added automatically when saved (Pakistan time).</p>
<label class="sign-label">Signature method<select id="signature-method" name="method"><option value="type">Type your name</option><option value="draw">Draw your signature</option><option value="upload">Upload an image</option></select></label>
<div id="type-options"><label class="sign-label">Signature style<select id="signature-style"><option value="classic">Classic script</option><option value="formal">Formal italic</option><option value="modern">Modern handwritten</option><option value="allura">Elegant — Allura</option><option value="great-vibes">Flourished — Great Vibes</option><option value="dancing-script">Flowing — Dancing Script</option><option value="sacramento">Fine-line — Sacramento</option><option value="caveat">Casual — Caveat</option></select></label></div>
<div id="upload-options" hidden><label class="sign-label">Signature image<input type="file" id="signature-upload" accept="image/png,image/jpeg"></label><p class="form-help">PNG or JPG, up to 2 MB. Use a tightly cropped image, ideally with a transparent background.</p></div>
<p id="signature-hint" class="form-help">Your typed name becomes the signature shown below.</p>
<canvas id="signature-pad" width="800" height="200" aria-label="Signature preview and drawing area"></canvas>
<button type="button" class="secondary" id="clear-signature">Clear signature</button>
<p class="form-help">The preview below your name is exactly the image placed on the agreement.</p>
<label class="sign-consent"><input type="checkbox" name="consent" value="1" required> <span>I have reviewed the sample agreement and agree to apply this signature for this demonstration.</span></label>
<p id="sign-error" role="alert"></p><button class="primary" id="save-signature" disabled>Save signed agreement →</button>
<p class="form-help">Completion is recorded only after the signed PDF is saved successfully.</p>
</div></section></div></form>
<script type="module" src="/direct-signing.js?v={{ hash_file('sha256', public_path('direct-signing.js')) }}"></script>
@endif
@if($history->isNotEmpty())<section class="card" style="margin-top:24px"><div class="card-head"><h2>Saved demonstrations</h2><span>Latest 10</span></div><div class="card-content">@foreach($history as $entry)<p><a class="text-link" href="/direct-signing-demo/{{ $entry->id }}/download">{{ $entry->data['name'] }} · {{ $entry->data['signed_at'] }} UTC · Download PDF ↓</a></p>@endforeach</div></section>@endif
@endsection


