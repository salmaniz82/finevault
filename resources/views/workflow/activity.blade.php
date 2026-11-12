@extends('layout')
@section('content')
<div class="page-head"><div><h1>Activity log</h1><p>Account, agreement, holding and service history.</p></div></div>
<section class="card table-wrap"><table><thead><tr><th>Activity</th><th>Client / account</th><th>By</th><th>When</th><th>Record</th></tr></thead><tbody>
@forelse($events as $event)
@php($url = match($event->kind) { 'holding'=>'/holding/'.$event->entry_id, 'request'=>'/requests/'.$event->entry_id, 'deal'=>'/deals/'.$event->entry_id, 'document'=>'/documents/'.$event->entry_id.'/download', default=>$event->subject_user_id ? ($event->subject_role === 'client' ? '/clients/'.$event->subject_user_id.'/onboarding' : '/managers') : null })
<tr><td>{{ \App\Services\Workflow::activityLabel($event) }}@if($event->new_status)<small> · {{ $event->new_status }}</small>@endif</td><td>{{ $event->client_name ?? $event->subject_name ?? '—' }}</td><td>{{ $event->actor_name ?? 'System / historical import' }}</td><td><time title="{{ $event->occurred_at }} UTC">{{ \Carbon\Carbon::parse($event->occurred_at,'UTC')->diffForHumans() }}</time></td><td>@if($url)<a class="text-link" href="{{ $url }}">View</a>@endif</td></tr>
@empty<tr><td colspan="5">No activity yet.</td></tr>@endforelse
</tbody></table></section>{{ $events->links('pagination::simple-bootstrap-5') }}
@endsection
