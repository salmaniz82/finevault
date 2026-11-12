<section class="card table-wrap"><table><thead><tr><th>Service / holding</th><th>Client</th><th>Manager</th><th>Status</th><th>Updated</th><th></th></tr></thead><tbody>
@forelse($requests as $item)
@php($status = \App\Services\Workflow::requestStatus($item->status))
@php($owner = \App\Models\User::with('managers')->find($item->client_id))
<tr data-search-row><td><strong>{{ $item->data['type'] }}</strong><small>{{ $item->data['holding_name'] }}</small></td><td>{{ $owner->name }}</td><td>{{ $owner->managers->pluck('name')->join(', ') ?: 'Unassigned' }}</td><td><span class="status-label"><i class="status-dot {{ str_replace(' ','-',$status) }}"></i>{{ ucfirst($status) }}</span></td><td><time title="{{ $item->updated_at }} UTC">{{ $item->updated_at->diffForHumans() }}</time></td><td><a class="text-link" href="/requests/{{ $item->id }}">Open →</a></td></tr>
@empty<tr><td colspan="6"><div class="empty">No client service requests.</div></td></tr>@endforelse
</tbody></table></section>
