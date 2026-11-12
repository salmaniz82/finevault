@php
$showAgreements = $user->role !== 'manager';
$recent = fn($items) => $items->sort(fn($a,$b) => $b->updated_at->getTimestamp() <=> $a->updated_at->getTimestamp() ?: $b->id <=> $a->id)->take(5);
$agreements = $showAgreements ? $recent($records->where('kind','deal')->whereIn('status',['published','reviewed'])) : collect();
$recentRequests = $recent($requests);
@endphp
<div class="page-head"><div><h1>{{ $isClient ? 'Your overview' : 'Overview' }}</h1><p>Recent agreements and service activity.</p></div></div>
<div class="dashboard-timelines {{ $showAgreements ? '' : 'dashboard-timelines--single' }}">
@if($showAgreements)
<section class="card dashboard-feed" aria-labelledby="dashboard-agreements-title">
    <div class="card-head"><div><h2 id="dashboard-agreements-title">Agreements</h2><p class="feed-caption">Five recent published agreements</p></div><a class="text-link" href="/deals">View all →</a></div>
    <ol class="dashboard-timeline">
    @forelse($agreements as $agreement)
        @php($signed = \App\Services\Workflow::agreementStatus($agreement) === 'Signed')
        <li class="dashboard-event" data-dashboard-agreement="{{ $agreement->id }}">
            <span class="event-marker {{ $signed ? 'event-marker--signed' : 'event-marker--pending' }}" aria-hidden="true">@include('parts.icon',['name'=>$signed ? 'check' : 'file'])</span>
            <div class="event-body">
                <a class="event-title" href="/deals/{{ $agreement->id }}">Agreement for {{ $agreement->data['client_name'] }}</a>
                <p class="event-context">FW-{{ $agreement->id }} · {{ count($agreement->data['items']) }} {{ count($agreement->data['items']) === 1 ? 'holding item' : 'holding items' }}</p>
                <div class="event-meta"><span class="event-status {{ $signed ? 'event-status--signed' : 'event-status--pending' }}"><i class="status-dot {{ $signed ? 'resolved' : 'pending' }}" aria-hidden="true"></i>{{ $signed ? 'Signed' : 'Pending signature' }}</span><time datetime="{{ $agreement->updated_at->toIso8601String() }}" title="{{ $agreement->updated_at->format('d M Y, g:i a') }}">Updated {{ $agreement->updated_at->diffForHumans() }}</time></div>
            </div>
        </li>
    @empty
        <li class="feed-empty">No published agreements yet.</li>
    @endforelse
    </ol>
</section>
@endif
<section class="card dashboard-feed" aria-labelledby="dashboard-services-title">
    <div class="card-head"><div><h2 id="dashboard-services-title">Service requests</h2><p class="feed-caption">Five recent requests</p></div><a class="text-link" href="/requests">View all →</a></div>
    <ol class="dashboard-timeline">
    @forelse($recentRequests as $service)
        @php($status = \App\Services\Workflow::requestStatus($service->status))
        <li class="dashboard-event" data-dashboard-service="{{ $service->id }}">
            <span class="event-marker event-marker--service" aria-hidden="true">@include('parts.icon',['name'=>'message'])</span>
            <div class="event-body">
                <a class="event-title" href="/requests/{{ $service->id }}">{{ $service->data['type'] }} by {{ $clientNames[$service->client_id] }}</a>
                <p class="event-context">{{ $service->data['holding_name'] }}</p>
                <div class="event-meta"><span class="event-status"><i class="status-dot {{ str_replace(' ','-',$status) }}" aria-hidden="true"></i>{{ ucfirst($status) }}</span><time datetime="{{ $service->updated_at->toIso8601String() }}" title="{{ $service->updated_at->format('d M Y, g:i a') }}">{{ $service->updated_at->diffForHumans() }}</time></div>
            </div>
        </li>
    @empty
        <li class="feed-empty">No service requests yet.</li>
    @endforelse
    </ol>
</section>
</div>
