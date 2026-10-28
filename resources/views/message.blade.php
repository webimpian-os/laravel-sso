@extends('sso::layout')

@section('title', config('app.name'))

@section('icon')
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
@endsection

@section('heading', "Can't sign you in")

@section('body')
    <p>{{ $message }}</p>
    <a class="button" href="{{ route('sso.login') }}">Sign in again</a>
@endsection
