@extends('layout', ['title'=>'Welcome back'])
@section('content')
<div class="login-wrap">
<section class="login-story login-story--vault" aria-labelledby="vault-heading">
    <div class="vault-scene" aria-hidden="true">
        <img class="vault-background" src="/images/vault-background-v2.png" alt="" fetchpriority="high" draggable="false">
        <div class="vault-wheel-mount">
            <img id="vault-wheel" class="vault-wheel-image" src="/images/vault-wheel-v2.png" alt="" draggable="false">
        </div>
    </div>
    <a class="login-vault-brand" href="/login" aria-label="Fineworth home">
        <img src="/images/fineworth-monogram.svg" width="40" height="54" alt="">
        <span>FINEWORTH</span>
    </a>
    <div class="login-vault-copy">
        <h1 id="vault-heading">Your collection.<br>Safely held.</h1>
        <p>Your assets and documents, in one private space.</p>
    </div>
    <div class="vault-status" aria-label="Vault locked">
        <svg class="vault-status-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <path d="M7 10V7a5 5 0 0 1 10 0v3" stroke="currentColor" stroke-width="1.8"/>
            <rect x="4" y="10" width="16" height="12" rx="3" fill="currentColor"/>
            <path d="M12 14v4" stroke="#10383b" stroke-width="2" stroke-linecap="round"/>
        </svg>
        <span>Vault locked</span>
    </div>
</section>
<section class="login-form"><div class="login-card"><p class="eyebrow copper">01 / ACCESS YOUR VAULT</p><h2>Welcome back.</h2><p class="muted">Your holdings and documents, in one private space.</p>
@if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="/login">@csrf<label>Email address<input name="email" type="email" autocomplete="username" value="{{ old('email') }}" placeholder="you@example.com" required></label><label>Password<div class="password-wrap"><input id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required><button type="button" class="password-toggle" aria-label="Show password">Show</button></div></label><div class="form-line"><label class="checkbox"><input type="checkbox" name="remember" value="1">Remember me</label><span class="muted">Need access? Contact your manager</span></div><button class="primary wide" type="submit">Sign in & unlock vault @include('parts.icon',['name'=>'arrow'])</button></form><div class="login-divider">LOCAL DEMONSTRATION</div><p class="muted">Explore the three roles using the sample accounts.</p><div class="demo-accounts"><button type="button" data-demo="admin@fineworth.test">Admin ↗</button><button type="button" data-demo="manager@fineworth.test">Manager ↗</button><button type="button" data-demo="client@fineworth.test">Client ↗</button></div><small class="muted">Sample password: <code>FineworthDemo!27</code><br>No email verification in this prototype.</small></div><div class="login-foot">FINEWORTH <span>Private client portal · Prototype</span></div></section></div>
@endsection

