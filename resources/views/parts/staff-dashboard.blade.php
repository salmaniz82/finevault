@php
$draftDeals = $records->where('kind','deal')->where('data.status','draft');
$openRequests = $requests->where('data.status','!=','completed');
$unassigned = $clients->filter(fn($c)=>empty($c->manager_ids));
$actionCount = $openRequests->count()+$draftDeals->count()+($user->role === 'admin' ? $pending->count()+$unassigned->count() : 0);
@endphp
<section class="card card-content"><p class="eyebrow copper">{{ $user->role === 'admin' ? 'ADMIN ACTIONS' : 'YOUR ACTIONS' }}</p><h2>{{ $actionCount }} items need attention</h2><p>Counts reflect saved records in your workspace. Completed work drops out of this queue.</p></section>
<div class="stats">
@foreach(($user->role === 'admin' ? [['Private drafts to review',$pending->count(),'Documents and profile changes','/approvals'],['Clients without a manager',$unassigned->count(),'Assign a manager to each client','/clients']] : []) as [$label,$value,$caption,$url])<a class="stat" href="{{ $url }}"><div class="stat-top">{{ $label }}</div><div class="stat-value">{{ $value }}</div><div class="stat-bottom">{{ $caption }} →</div></a>@endforeach
@foreach([['Open service requests',$openRequests->count(),'Inspection, regauge and re-evaluation','/requests'],['Deals to prepare',$draftDeals->count(),'Generate agreement PDFs','/deals']] as [$label,$value,$caption,$url])<a class="stat" href="{{ $url }}"><div class="stat-top">{{ $label }}</div><div class="stat-value">{{ $value }}</div><div class="stat-bottom">{{ $caption }} →</div></a>@endforeach
</div>
<section class="card"><div class="card-head"><h2>Action queue</h2></div>
@if($user->role === 'admin')@foreach($pending as $item)<div class="document-row"><div class="file-copy"><h3>{{ $item->data['title'] ?? 'Profile correction' }}</h3><p>{{ $clientNames[$item->client_id] }} · Private draft awaiting approval</p></div><a class="text-link" href="/approvals">Review →</a></div>@endforeach
@foreach($unassigned as $person)<div class="document-row"><div class="file-copy"><h3>{{ $person->name }}</h3><p>Assign a manager</p></div><a class="text-link" href="/client/{{ $person->id }}">Assign →</a></div>@endforeach @endif
@foreach($draftDeals as $item)<div class="document-row"><div class="file-copy"><h3>Prepare agreement · {{ $item->data['name'] }}</h3><p>{{ $clientNames[$item->client_id] }} · FW-{{ $item->id }}</p></div><a class="text-link" href="/deals/{{ $item->id }}">Prepare →</a></div>@endforeach
@foreach($openRequests as $item)<div class="document-row"><div class="file-copy"><h3>{{ $item->data['type'] }} · {{ $item->data['holding_name'] }}</h3><p>{{ $clientNames[$item->client_id] }} · {{ ucfirst($item->data['status']) }}</p></div><a class="text-link" href="/requests">Follow up →</a></div>@endforeach
@if(!$actionCount)<div class="empty">No outstanding actions. New submissions and requests will appear here.</div>@endif</section>
<section class="card card-content"><h2>Client onboarding</h2><p>Create a client account, assign a manager, record an externally received deposit and prepare the first deal. Use the same client for repeat deals.</p><a class="text-link" href="/clients">{{ $user->role === 'admin' ? 'Create or manage clients' : 'View assigned clients' }} →</a> &nbsp; <a class="text-link" href="/deals">Prepare a deal →</a></section>
