@php($people = $page === 'clients' ? $clients : $managers)
<section class="card">
    <div class="toolbar">
        <h2>{{ ucfirst($page) }} · {{ $people->count() }}</h2>
        <label class="search" style="margin:0">
            @include('parts.icon',['name'=>'search'])
            <input data-search aria-label="Search {{ $page }}" placeholder="Search name or email…">
        </label>
    </div>
    <div class="table-wrap people-list" role="region" aria-label="{{ ucfirst($page) }} list" tabindex="0">
        <table>
            <thead><tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                @if($page === 'clients')<th scope="col">Holdings</th><th scope="col">Managers / onboarding</th>
                @else<th scope="col">Assigned clients</th>@endif
                <th scope="col">Actions</th>
            </tr></thead>
            <tbody>
                @forelse($people as $person)
                    <tr data-search-row>
                        <td><div class="people-name"><span class="avatar" aria-hidden="true">{{ mb_substr($person->name,0,1) }}</span><strong>{{ $person->name }}</strong></div></td>
                        <td>{{ $person->email }}</td>
                        @if($page === 'clients')
                            <td>{{ \App\Services\HoldingMeasurements::totals($holdings->where('client_id',$person->id)) }}</td>
                            <td>{{ count($person->manager_ids ?? []) }} assigned<small>{{ ucfirst($person->clientProfile?->onboarding_status ?? 'draft') }}</small></td>
                            <td><a class="text-link" href="/client/{{ $person->id }}" aria-label="View client {{ $person->name }}">View client →</a></td>
                        @else
                            <td>{{ $clients->filter(fn($c)=>in_array($person->id,$c->manager_ids ?? []))->count() }} clients</td>
                            <td><a class="text-link" href="/clients" aria-label="Manage client assignments for {{ $person->name }}">Assign clients →</a></td>
                        @endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $page === 'clients' ? 5 : 4 }}"><div class="empty">No {{ $page }} yet. Accounts will appear here once created or assigned.</div></td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="empty" data-search-empty hidden>No {{ $page }} match your search.</div>
    </div>
</section>
