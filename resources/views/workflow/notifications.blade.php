@extends('layout')
@section('content')
<div class="page-head"><h1>Notifications</h1></div><section class="card">
@forelse($notifications as $notification)<div class="document-row"><div class="file-copy"><h3>{{ $notification->message }}</h3><p>{{ \Carbon\Carbon::parse($notification->created_at,'UTC')->diffForHumans() }} · {{ $notification->read_at ? 'Read' : 'New' }}</p></div><form method="post" action="/notifications/{{ $notification->id }}/read">@csrf<button class="secondary">Open</button></form></div>@empty<div class="empty">No notifications yet.</div>@endforelse
</section>{{ $notifications->links('pagination::simple-bootstrap-5') }}
@endsection
