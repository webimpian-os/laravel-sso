@extends('sso::layout')

@section('head')
    <meta name="referrer" content="no-referrer">
@endsection

@section('title', 'Signing out')

@section('icon')
    <svg class="spin" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" opacity=".25"/><path d="M21 12a9 9 0 0 0-9-9"/></svg>
@endsection

@section('heading', 'Signing you out…')

@section('body')
    <p>Taking you back to Web Impian SSO.</p>
    <form method="POST" action="{{ $action }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <button class="button secondary" type="submit">Continue signing out</button>
    </form>
@endsection

@section('scripts')
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">document.forms[0].submit();</script>
@endsection
