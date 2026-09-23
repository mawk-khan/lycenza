{{--
    Laravel's stock 403 page plus a Log out control for a signed-in
    user: a web 403 is rendered by Blade, not Inertia, so the Inertia
    account bar (resources/js/Layouts/AccountLayout.vue) is not on it.
    Same POST /logout endpoint and CSRF token as everywhere else.
--}}
@extends('errors::minimal')

@section('title', __('Forbidden'))
@section('code', '403')
@section('message')
    {{ __($exception->getMessage() ?: 'Forbidden') }}
    @auth
        <form method="POST" action="{{ route('logout') }}" style="margin-top: 0.75rem; font-size: 0.875rem;">
            @csrf
            <span style="color: #6b7280;">Signed in as {{ auth()->user()->email }}.</span>
            <button type="submit" data-testid="logout" style="margin-left: 0.5rem; text-decoration: underline; cursor: pointer; background: none; border: 0; padding: 0; font: inherit;">Log out</button>
        </form>
    @endauth
@endsection
