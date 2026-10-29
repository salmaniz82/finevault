<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#222d25"><title>{{ $title ?? 'Your private vault' }} · Fineworth</title><link rel="stylesheet" href="/portal.css"><script src="/portal.js" defer></script></head>
<body class="{{ request()->is('login') ? 'login-body' : '' }}" data-old="{{ json_encode(collect(old())->except(['_token','password'])->all()) }}">
@auth
<aside class="sidebar" id="navigation">
    <a class="brand" href="/dashboard"><span class="monogram">F<span>W</span></span><span>FINEWORTH<small>PRIVATE CLIENT PORTAL</small></span></a>
    <div class="workspace"><span class="workspace-icon">FW</span><div>{{ auth()->user()->role === 'client' ? 'My private vault' : 'Portfolio workspace' }}<small>{{ ucfirst(auth()->user()->role) }} access</small></div><span class="dot"></span></div>
    <p class="nav-label">WORKSPACE</p>
    <nav aria-label="Main navigation">
        @php($navigation = ['dashboard'=>['Overview','grid'], 'holdings'=>['Holdings','bottle'], 'documents'=>['Documents','file'], 'requests'=>['Service requests','message']])
        @if(auth()->user()->role !== 'client') @php($navigation += ['clients'=>['Clients','users']]) @endif
        @if(auth()->user()->role === 'admin') @php($navigation += ['managers'=>['Managers','shield'], 'approvals'=>['Review & approvals','check']]) @endif
        @if(auth()->user()->role === 'admin') @php($navigation += ['signing-demo'=>['Signing demo','file']]) @endif
        @foreach($navigation as $path => [$label, $icon])
        <a href="/{{ $path }}" class="{{ request()->segment(1) === $path || ($path === 'holdings' && request()->segment(1) === 'holding') || ($path === 'clients' && request()->segment(1) === 'client') ? 'active' : '' }}">@include('parts.icon', ['name'=>$icon]) {{ $label }} @if($path === 'approvals' && isset($records) && $records->whereIn('kind',['document','profile'])->where('data.status','pending')->count())<span class="nav-count">{{ $records->whereIn('kind',['document','profile'])->where('data.status','pending')->count() }}</span>@endif</a>
        @endforeach
    </nav>
    <div class="sidebar-bottom"><div class="vault-note">@include('parts.icon',['name'=>'shield'])<div>Safely held.<small>Your collection. One private space.</small></div></div><a href="/account" class="user-block"><span class="avatar">{{ substr(auth()->user()->name,0,1) }}{{ substr(strrchr(auth()->user()->name,' '),1,1) }}</span><span>{{ auth()->user()->name }}<small>{{ ucfirst(auth()->user()->role) }} account</small></span><span>↗</span></a></div>
</aside>
<div class="app-shell"><header class="topbar"><button class="mobile-menu secondary" type="button" aria-controls="navigation" aria-expanded="false">☰</button><div class="breadcrumb">Workspace <span>/</span> <strong>{{ $title ?? 'Overview' }}</strong></div><div class="topbar-right"><span class="prototype">PROTOTYPE · SAMPLE DATA</span><span class="avatar small">{{ substr(auth()->user()->name,0,1) }}</span></div></header><main id="main-content">
@if(session('success'))<div class="notice" role="status">@include('parts.icon',['name'=>'check']) {{ session('success') }}</div>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Please check the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
<footer>FINEWORTH <span>Private collection & document management</span><span>Fictional records · Demonstration only</span></footer></main></div>
@else
@yield('content')
@endauth
</body></html>
