@extends('layout')
@section('content')
<div class="page-head"><div><h1>Agreements</h1><p>{{ $user->role === 'client' ? 'Published agreements, signing status and locked signed copies.' : 'Prepare drafts, publish for signature and release linked holdings.' }}</p></div>@if($user->role === 'admin')<a class="button primary" href="{{ route('deals.create') }}">Create agreement</a>@endif</div>
<div class="toolbar">@foreach(['Draft','Draft for review','Pending signature','Signed'] as $state)<span class="badge">{{ $state }} · {{ $deals->filter(fn($d)=>\App\Services\Workflow::agreementStatus($d) === $state)->count() }}</span>@endforeach</div>
<section class="card table-wrap"><table><thead><tr><th>Agreement / client</th><th>Items</th><th>Status</th><th></th></tr></thead><tbody>@forelse($deals as $deal)<tr><td>FW-{{ $deal->id }} · {{ $deal->data['client_name'] }}</td><td>{{ count($deal->data['items']) }} stock items</td><td>{{ \App\Services\Workflow::agreementStatus($deal) }}</td><td><a class="text-link" href="/deals/{{ $deal->id }}">Open →</a></td></tr>@empty<tr><td colspan="4">No agreements yet.</td></tr>@endforelse</tbody></table></section>
@endsection
