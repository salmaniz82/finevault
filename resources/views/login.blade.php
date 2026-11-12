@extends('layout', ['title' => 'Access your vault'])
@php($cinematic = app()->environment('local', 'staging') && request()->boolean('animated'))
@push('styles')
@if($cinematic)
<link rel="stylesheet" href="/vault-cinematic.css">
<script src="/vault-cinematic.js" defer></script>
@else
<link rel="preload" href="/images/login-background.webp" as="image" type="image/webp">
@endif
<link rel="stylesheet" href="/login.css">
@endpush
@section('content')
<main class="vault-login{{ $cinematic ? ' cinematic-preview' : '' }}">
    @if($cinematic)
        <video class="unlock-film" muted playsinline preload="none" aria-hidden="true"></video>
        <button class="unlock-skip" hidden type="button">Continue</button>
    @endif
    <div class="vault-login-content">
        <a class="vault-login-brand" href="/login" aria-label="Fineworth home">
            <img src="/images/fineworth-logo-orange.png" alt="Fineworth" width="2048" height="401">
            <span>Building wealth with premium assets</span>
        </a>
        <section class="vault-login-panel" aria-labelledby="login-heading">
            <div class="vault-login-heading">
                <p class="vault-login-eyebrow">Private client access</p>
                <h1 id="login-heading">The Vault</h1>
                <p>Sign in to your private collection.</p>
            </div>
            @if($errors->any())
                <div class="vault-login-error" role="alert">{{ $errors->first() }}</div>
            @endif
            <form method="post" action="/login" class="vault-login-fields">
                @csrf
                <div class="vault-login-field">
                    <input id="login-email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>
                    <label for="login-email">Email address</label>
                </div>
                <div class="vault-login-field vault-login-password">
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <label for="password">Password</label>
                    <button type="button" class="password-toggle" aria-label="Show password" aria-pressed="false">
                        <span class="password-show-icon">@include('parts.icon', ['name' => 'eye'])</span>
                        <span class="password-hide-icon">@include('parts.icon', ['name' => 'eye-off'])</span>
                    </button>
                </div>
                <label class="vault-login-remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))>Remember me</label>
                <button class="vault-login-submit" type="submit">Sign in &amp; unlock vault @include('parts.icon', ['name' => 'arrow'])</button>
            </form>
            <p class="vault-login-help">Need access? Contact your manager.</p>
        </section>
        <p class="vault-login-footer">Your collection. Safely held.</p>
    </div>
</main>
@endsection
