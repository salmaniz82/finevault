@php($currentPublication = isset($holding) ? \Illuminate\Support\Facades\DB::table('holding_publications')->where('holding_id',$holding->id)->latest('id')->value('update_id') : null)
@foreach($updates as $update)
<section class="card card-content"><div class="request-top"><h3>{{ $update->title }}</h3><span class="badge">{{ ucfirst($update->status) }}{{ $currentPublication == $update->id ? " · Current published version" : "" }}</span></div><p>{{ $update->notes }}</p><small>Submitted {{ \Carbon\Carbon::parse($update->created_at,'UTC')->diffForHumans() }}</small>
@php($snapshot = json_decode($update->snapshot,true))
<details><summary>Holding snapshot</summary><dl class="metadata">@foreach($snapshot as $field=>$value)<div><dt>{{ ucfirst(str_replace('_',' ',$field)) }}</dt><dd>{{ is_scalar($value) ? $value : '' }}</dd></div>@endforeach</dl></details>
@foreach(\Illuminate\Support\Facades\DB::table('holding_update_files')->where('update_id',$update->id)->get() as $file)<p>@if(in_array(strtolower(pathinfo($file->path,PATHINFO_EXTENSION)),['png','jpg','jpeg']))<a href="/update-files/{{ $file->id }}"><img class="package-photo" src="/update-files/{{ $file->id }}?view=1" alt="{{ $file->original_name }}" loading="lazy"></a><br>@endif<a class="text-link" href="/update-files/{{ $file->id }}">{{ $file->original_name }} ↓</a></p>@endforeach
@if($update->review_note && auth()->user()->role !== 'client')<div class="note-panel">Review: {{ $update->review_note }}</div>@endif
@if(auth()->user()->role === 'admin')
@if($update->status === 'pending review')<form method="post" action="/updates/{{ $update->id }}/review">@csrf<label>Review note (required when returning)<input name="reason" maxlength="1000"></label><div class="form-actions"><button class="secondary" name="decision" value="returned">Return package</button><button class="primary" name="decision" value="approved">Approve whole package</button></div></form>
@elseif($update->status === 'approved')<form method="post" action="/updates/{{ $update->id }}/restore">@csrf<label>Reason for restoring this package<input name="reason" required maxlength="1000"></label><button class="secondary">Restore as current version</button></form>@endif
@endif</section>
@endforeach
