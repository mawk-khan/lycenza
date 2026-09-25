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
        {{-- Phase 0O.3: classes, not inline styles (enforced CSP, style-src 'self'). --}}
        <form method="POST" action="{{ route('logout') }}" class="mt-3 text-sm">
            @csrf
            <span class="text-gray-500">Signed in as {{ auth()->user()->email }}.</span>
            <button type="submit" data-testid="logout" class="ml-2 cursor-pointer border-0 bg-transparent p-0 underline">Log out</button>
        </form>
    @endauth
@endsection
