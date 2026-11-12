@extends('layout')
@section('content')
@php($status = \App\Services\Workflow::requestStatus($entry->status))
<div class="page-head"><div><a class="text-link" href="/requests">← Service requests</a><h1>{{ $entry->data['type'] }} · {{ $holding->data['name'] }}</h1><p>{{ \App\Models\User::find($entry->client_id)->name }}</p></div><span class="status-label"><i class="status-dot {{ str_replace(' ','-',$status) }}"></i>{{ ucfirst($status) }}</span></div>
<div class="detail-grid"><div><section class="card card-content"><h2>Request details</h2><p>{{ $entry->data['notes'] }}</p><p><a class="text-link" href="/holding/{{ $holding->id }}">View holding →</a></p>
@if(auth()->user()->role !== 'client' && $status === 'pending')<form method="post" action="/requests/{{ $entry->id }}">@csrf<input type="hidden" name="status" value="in progress"><label>Team note (visible to client)<input name="staff_note" maxlength="2000"></label><button class="primary">Start work</button></form>@endif
</section>
@if(auth()->user()->role !== 'client' && $status === 'in progress')@include('workflow.update-form')@endif
@include('workflow.packages')
</div><aside><section class="card card-content"><h2>Progress timeline</h2><ol class="request-timeline"><li><strong>Pending</strong><time title="{{ $entry->created_at }} UTC">{{ $entry->created_at->diffForHumans() }}</time><p>Client submitted the request.</p></li>
@foreach($entry->data['history'] ?? [] as $event)<li><strong>{{ ucfirst(\App\Services\Workflow::requestStatus($event['status'])) }}</strong><time title="{{ $event['at'] }}">{{ \Carbon\Carbon::parse($event['at'],'UTC')->diffForHumans() }}</time><p>{{ $event['staff_note'] ?? '' }}</p><small>{{ $event['by'] }}</small></li>@endforeach</ol></section></aside></div>
@endsection
