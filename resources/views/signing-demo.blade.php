@extends('layout')
@section('content')
@if($configurationIssues)<div class="notice error" role="alert"><div><strong>DocuSign setup needs attention</strong><ul>@foreach($configurationIssues as $issue)<li>{{ $issue }}</li>@endforeach</ul></div></div>@endif
<div class="page-head"><div><p class="eyebrow copper">DOCUSIGN · SANDBOX</p><h1>From agreement to signature.</h1><p>Try the client journey with a prepared agreement. No client setup required.</p></div><span class="badge">Admin demonstration</span></div>
<div class="detail-grid"><div>
<section class="card"><div class="card-head"><h2>Alexander Morgan’s onboarding</h2><span class="badge {{ $status === 'completed' ? 'approved' : 'pending' }}">{{ ucfirst($status) }}</span></div>
<div class="card-content"><p class="eyebrow copper">FICTIONAL CLIENT · FW-DEMO-2026-001</p><h3>Glenbrae 2018 · Cask GB-1842</h3><p>Single malt Scotch whisky · First-fill bourbon barrel · 200 L cask capacity</p>
<div class="metadata">@foreach(['Original litres of alcohol (OLA)'=>'127.0 L','Regauged litres of alcohol (RLA)'=>'113.4 L','Current bulk volume / ABV'=>'180.0 L / 63.0%','Purchase price'=>'GBP 12,500.00','Deposit paid externally'=>'GBP 2,500.00 (20%)','Balance due'=>'GBP 10,000.00 · 12 November 2026','Annual management fee'=>'1.0% of purchase price (GBP 125.00)','Brokerage fee'=>'2.0% of future gross sale proceeds'] as $label=>$value)<div><small>{{ $label }}</small><strong>{{ $value }}</strong></div>@endforeach</div>
<div class="note-panel" style="margin:20px 0">Demonstration only. The client, distillery, cask, payment and agreement terms are imaginary. Signing uses DocuSign’s developer sandbox.</div>
<a class="secondary" href="/signing-demo/sample" target="_blank" rel="noopener">View sample PDF ↗</a>
<form method="post" action="/signing-demo/sign" style="margin-top:18px">@csrf<button class="primary" @disabled(!$ready || $status === 'completed')>{{ session('docusign_demo.envelope') ? 'Continue signing document' : 'Sign document with DocuSign' }} →</button></form>
@if($status === 'completed')<p><a class="text-link" href="/signing-demo/signed">Download completed agreement ↓</a></p>@endif
</div></section>
</div><aside>
<section class="card"><div class="card-head"><h2>The client journey</h2></div><div class="card-content steps"><div class="step"><div><strong>Deposit recorded</strong>GBP 2,500 paid outside the portal, shown as fictional sample data.</div></div><div class="step"><div><strong>Review the agreement</strong>Read the cask details, fees, balance deadline and sample terms.</div></div><div class="step"><div><strong>Sign with DocuSign</strong>The admin acts as the imaginary client in DocuSign’s signing screen.</div></div><div class="step"><div><strong>Return to the portal</strong>Completion is checked directly with DocuSign before the signed PDF becomes available.</div></div></div></section>
<section class="card"><div class="card-head"><h2>Demo controls</h2></div><div class="card-content"><p>{{ $ready ? 'Sandbox credentials configured.' : 'Sandbox credentials need configuration.' }}</p><p class="form-help">If DocuSign asks for account consent, connect using the developer account first. Register the consent callback URI shown in the setup guide.</p><form method="post" action="/signing-demo/connect">@csrf<button class="secondary" @disabled(!$ready)>Connect DocuSign</button></form>
@if(session('docusign_demo.envelope'))<form method="post" action="/signing-demo/refresh" style="margin-top:12px">@csrf<button class="secondary">Refresh status</button></form><form method="post" action="/signing-demo/reset" style="margin-top:12px">@csrf<button class="secondary">Reset demonstration</button></form>@endif
<p class="form-help">No client account is created. The sample recipient uses example.com and embedded signing; no email invitation is requested. Reset starts a new sandbox envelope and retains earlier envelopes in DocuSign.</p></div></section>
</aside></div>
@endsection
