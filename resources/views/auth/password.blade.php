@extends('layouts.app')
@section('title', 'Change Password')
@section('content')
<div class="mx-auto max-w-lg"><div class="page-heading"><div><h1 class="text-xl font-bold">Change password</h1><p class="help">Set a personal password for your account.</p></div></div>
@if(auth()->user()->must_change_password)<div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">Change your initial password before continuing.</div>@endif
<form action="{{ route('password.update') }}" method="POST" class="card space-y-3 p-4" data-submit-once>@csrf @method('PUT')
<div><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" required autocomplete="current-password"></div>
<div><label for="password">New password</label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"><p class="help">At least 10 characters with uppercase and lowercase letters and numbers.</p></div>
<div><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"></div><button type="submit" class="btn">Save password</button></form></div>
@endsection
