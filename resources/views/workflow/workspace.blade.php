@php
use App\Services\HoldingMeasurements as Measures;
$titles = ['dashboard'=>'Overview','holdings'=>'Holdings','holding'=>'Holding details','requests'=>'Service requests','clients'=>'Clients','client'=>'Client profile','managers'=>'Managers','account'=>'My account'];
$title = $titles[$page] ?? 'Workspace';
$isClient = $user->role === 'client';
$holdings = $records->where('kind','holding');
$documents = $records->where('kind','document');
$requests = $records->where('kind','request');
$clientNames = $clients->pluck('name','id');
@endphp
@extends('layout')
@section('content')
@if($page === 'dashboard')
@include('workflow.dashboard')
@elseif($page === 'holdings')
<div class="page-head"><div><h1>Holdings</h1><p>{{ $isClient ? 'Your released whisky casks and fine wine.' : 'Physical holdings, agreement links and release status.' }}</p></div>@if($user->role === 'admin')<button class="primary" data-open="holding-modal">Add holding</button>@endif</div>
<section class="card"><div class="toolbar"><label class="search"><input data-search placeholder="Search holdings or reference…" aria-label="Search holdings"></label></div>@include('parts.holdings-table',['items'=>$holdings])</section>
@elseif($page === 'holding')
@php($asset = $selected->data)
@php($holding = $selected)
<div class="page-head"><div><a class="text-link" href="/holdings">← Holdings</a><h1>{{ $asset['name'] }}</h1><p>{{ $asset['reference'] }} · {{ $clientNames[$selected->client_id] }}</p></div>@if($isClient)<button class="primary" data-open="request-modal">Request service</button>@endif</div>
<div class="detail-grid"><div><section class="card card-content"><h2>Current holding details</h2><dl class="metadata">@foreach($asset as $key=>$value)<div><dt>{{ ucfirst(str_replace('_',' ',$key)) }}</dt><dd>{{ $key === 'quantity' ? Measures::quantity($asset) : $value }}</dd></div>@endforeach</dl></section>
@if($user->role === 'admin')<section class="card card-content"><h2>Agreement and release</h2><p>{{ $selected->holding->released_at ? 'Released to client' : 'Not released · hidden from client' }}</p>
@if($selected->holding->agreement_id)<p><a class="text-link" href="/deals/{{ $selected->holding->agreement_id }}">Open linked agreement →</a></p>@unless($selected->holding->released_at)<form method="post" action="/holdings/{{ $selected->id }}/release">@csrf<button class="primary">Release after verified signing</button></form>@endunless
@else<form method="post" action="/holdings/{{ $selected->id }}/agreement">@csrf<label>Agreement<select name="agreement_id" required>@foreach($records->where('kind','deal')->where('client_id',$selected->client_id) as $agreement)<option value="{{ $agreement->id }}">FW-{{ $agreement->id }} · {{ \App\Services\Workflow::agreementStatus($agreement) }}</option>@endforeach</select></label><button class="secondary">Link agreement</button></form>@endif</section>
@include('workflow.update-form')@endif
@php($updatesQuery = \Illuminate\Support\Facades\DB::table('holding_updates')->where('holding_id',$selected->id))
@php($updates = ($isClient ? $updatesQuery->where('status','approved') : $updatesQuery)->latest('id')->get())
<div class="card-head"><h2>Update packages & history</h2></div>@include('workflow.packages')
<section class="card"><div class="card-head"><h2>Earlier document history</h2></div>@include('parts.documents',['items'=>$documents->where('data.holding_id',$selected->id)])</section>
</div><aside><section class="card card-content"><h2>Publication history</h2>
@foreach(\Illuminate\Support\Facades\DB::table('holding_publications')->where('holding_id',$selected->id)->latest('id')->get() as $publication)<p>Package #{{ $publication->update_id }} · <time title="{{ $publication->published_at }} UTC">{{ \Carbon\Carbon::parse($publication->published_at,'UTC')->diffForHumans() }}</time>@if(!$isClient)<br>{{ $publication->reason }}@endif</p>@endforeach
<p class="form-help">Approved snapshots are retained. Restoring a package does not reopen a resolved service request.</p></section></aside></div>
@elseif($page === 'requests')
<div class="page-head"><div><h1>Service requests</h1><p>Client requests for evaluation, regauge, inspection and photographs.</p></div>@if($isClient && $holdings->isNotEmpty())<button class="primary" data-open="request-modal">New request</button>@endif</div>
<div class="toolbar"><div class="tabs"><a class="tab" href="/requests">All · {{ $requests->count() }}</a>@foreach(['pending','in progress','pending review','resolved'] as $state)<a class="tab" href="/requests?status={{ urlencode($state) }}"><i class="status-dot {{ str_replace(' ','-',$state) }}"></i> {{ ucfirst($state) }} · {{ $requests->filter(fn($r)=>\App\Services\Workflow::requestStatus($r->status) === $state)->count() }}</a>@endforeach</div></div>
<div class="toolbar"><label class="search"><input data-search aria-label="Search service requests" placeholder="Search client, holding or status…"></label></div>
@include('workflow.requests',['requests'=>in_array(request('status'),['pending','in progress','pending review','resolved']) ? $requests->filter(fn($r)=>\App\Services\Workflow::requestStatus($r->status) === request('status')) : $requests])
@elseif(in_array($page,['clients','managers']))
<div class="page-head"><div><h1>{{ ucfirst($page) }}</h1><p>{{ $page === 'clients' ? 'Onboarding, manager assignments and client holdings.' : 'Managers and their assigned clients.' }}</p></div>@if($user->role === 'admin')<button class="primary" data-open="account-modal">Create {{ $page === 'clients' ? 'client' : 'manager' }}</button>@endif</div>@include('parts.people-list')
@elseif($page === 'client')
<div class="page-head"><div><a class="text-link" href="/clients">← Clients</a><h1>{{ $client->name }}</h1><p>{{ $client->email }} · Onboarding {{ $client->clientProfile->onboarding_status }}</p></div><a class="primary button" href="/clients/{{ $client->id }}/onboarding">{{ $user->role === 'admin' ? 'Review onboarding' : 'View onboarding checklist' }}</a></div>
<div class="detail-grid"><div><section class="card"><div class="card-head"><h2>Client holdings</h2>@if($user->role === 'admin')<button class="secondary" data-open="holding-modal">Add holding</button>@endif</div>@include('parts.holdings-table',['items'=>$holdings->where('client_id',$client->id)])</section>
<section class="card card-content"><h2>Personal details</h2><p>{{ $client->clientProfile->phone ?? 'Phone not provided' }}</p><p>{{ $client->clientProfile->address ?? 'Address not provided' }}</p><p class="form-help">Manage details in onboarding before submission. Confirmed details are locked.</p></section></div><aside>
@if($user->role === 'admin')<section class="card card-content"><h2>Manager assignment</h2><form method="post" action="/clients/{{ $client->id }}/assign">@csrf
@foreach($managers as $manager)<label class="checkbox"><input type="checkbox" name="manager_ids[]" value="{{ $manager->id }}" @checked(in_array($manager->id,$client->manager_ids))>{{ $manager->name }}</label>@endforeach<button class="primary">Save assignment</button></form></section>@endif
</aside></div>
@elseif($page === 'account')
<div class="page-head"><h1>My account</h1></div><section class="card card-content"><h2>{{ $user->name }}</h2><p>{{ $user->email }} · {{ ucfirst($user->role) }}</p><form method="post" action="/logout">@csrf<button class="secondary">Sign out</button></form></section>
@endif
@if($user->role === 'admin')@include('parts.holding-form')@endif
@if($isClient && in_array($page,['requests','holding']))@include('parts.request-form')@endif
@if($user->role === 'admin' && in_array($page,['clients','managers']))@include('parts.account-form')@endif
@endsection
