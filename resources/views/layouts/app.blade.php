<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="theme-color" content="#00677f"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Dashboard') | {{ config('app.name') }}</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
@include('pwa.head')
</head>
<body>
@php($unreadNotificationCount = auth()->user()->unreadNotifications()->count())
<a href="#main-content" class="skip-link">Skip navigation</a>
<div class="flex min-h-dvh flex-col">
<header class="no-print sticky top-0 z-40 border-b border-border bg-white/95 backdrop-blur">
<div class="mx-auto flex min-h-12 w-full max-w-[1280px] items-center gap-2 px-3">
<a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-3"><img src="{{ asset('assets/img/mms.png') }}" alt="MKM" class="h-7 w-auto max-w-24 object-contain"><span class="hidden h-7 w-px bg-slate-200 sm:block"></span><div class="min-w-0"><div class="truncate text-sm font-bold sm:text-base">MKM Scrap Delivery</div><div class="hidden text-[11px] text-muted sm:block">Goods Release</div></div></a>
<nav class="ml-auto hidden h-12 items-stretch xl:flex" aria-label="Main navigation">@include('partials.navigation')</nav>
<details class="relative ml-auto xl:ml-1" data-menu><summary class="flex min-h-11 items-center gap-2 rounded-xl px-2 hover:bg-slate-50" aria-label="Account menu{{ $unreadNotificationCount ? ', '.$unreadNotificationCount.' unread notifications' : '' }}"><span class="relative inline-flex"><x-user-avatar :user="auth()->user()" />@if($unreadNotificationCount)<span class="absolute -right-1.5 -top-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-red-600 px-1 text-[9px] font-bold leading-none text-white" aria-hidden="true">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span>@endif</span><span class="hidden text-left xl:block"><span class="block max-w-36 truncate text-xs font-semibold">{{ auth()->user()->name }}</span><span class="block max-w-36 truncate text-[10px] text-muted">{{ auth()->user()->job_title ?: auth()->user()->roleLabel() }}</span></span><span class="text-xs text-muted">⌄</span></summary><div class="user-menu !mt-1 !w-72 !rounded-lg"><div class="px-2.5 py-2"><div class="flex items-center gap-2"><x-user-avatar :user="auth()->user()" size="size-9" /><div class="min-w-0"><p class="truncate text-xs font-semibold">{{ auth()->user()->name }}</p><p class="truncate text-[10px] text-muted">{{ auth()->user()->email }}</p></div></div><dl class="mt-2 grid grid-cols-[66px_minmax(0,1fr)] gap-x-1.5 gap-y-0.5 text-[10px] leading-4"><dt class="text-muted">Job title</dt><dd class="break-words">{{ auth()->user()->job_title ?: 'Not provided' }}</dd><dt class="text-muted">Department</dt><dd class="break-words">{{ auth()->user()->department ?: 'Not provided' }}</dd><dt class="text-muted">Section</dt><dd class="break-words">{{ auth()->user()->section ?: 'Not provided' }}</dd><dt class="text-muted">Access</dt><dd class="font-semibold text-primary">{{ auth()->user()->roleLabel() }}</dd></dl></div><a href="{{ route('notifications.index') }}" class="flex min-h-10 items-center justify-between gap-2 border-t border-border px-3 text-xs font-semibold hover:bg-primary-soft {{ request()->routeIs('notifications.*') ? 'bg-primary-soft text-primary' : 'text-text-soft' }}"><span class="flex items-center gap-2"><x-icon name="check" class="!size-4" />Notifications</span><span class="rounded-full bg-primary-soft px-2 py-0.5 text-[10px] text-primary" aria-label="{{ $unreadNotificationCount }} unread notifications">{{ $unreadNotificationCount > 99 ? '99+' : $unreadNotificationCount }}</span></a><div class="border-t border-border px-3 py-1">@include('pwa.button',['installClass'=>'inline-flex min-h-8 items-center text-xs font-semibold text-primary'])</div><div class="flex items-center justify-between gap-1 border-t border-border px-2 py-1">@if(auth()->user()->local_login_enabled)<a href="{{ route('password.edit') }}" class="inline-flex min-h-8 items-center px-1 text-[10px] font-semibold text-text-soft hover:text-primary">Change password</a>@endif @if(auth()->user()->sso_object_id)<form action="{{ route('profile.refresh') }}" method="POST">@csrf<button type="submit" class="inline-flex min-h-8 items-center px-1 text-[10px] font-semibold text-primary">Sync profile</button></form>@endif</div></div></details>
<details class="xl:hidden" data-menu><summary class="flex size-10 items-center justify-center rounded-lg border border-border" aria-label="Open navigation">☰</summary><nav class="absolute inset-x-0 top-full grid border-b border-border bg-white p-3 shadow-lg" aria-label="Mobile navigation">@include('partials.navigation')</nav></details>
</div>
</header>
<main id="main-content" class="mx-auto w-full max-w-[1280px] min-w-0 flex-1 px-3 py-3" tabindex="-1">@include('partials.alerts')@yield('content')</main>
<footer class="border-t border-border bg-white px-3 py-1.5 text-right text-[10px] text-muted">Copyright PT Mitsubishi Krama Yudha Motors and Manufacturing © {{ now()->year }}</footer>
</div>
@include('pwa.help')
</body>
</html>
