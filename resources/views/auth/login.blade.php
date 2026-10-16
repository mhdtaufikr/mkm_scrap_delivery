<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#00677f"><title>Login | MKM Scrap Delivery</title>@vite(['resources/css/app.css', 'resources/js/app.js'])@include('pwa.head')
</head>
<body class="bg-slate-100">
<main class="flex min-h-dvh items-center justify-center px-4 py-6">
<section class="w-full max-w-md overflow-hidden rounded-2xl border border-border bg-white shadow-xl">
<div class="bg-primary px-5 py-4 text-white"><div class="flex items-center gap-3"><div class="flex h-11 w-20 shrink-0 items-center justify-center rounded-lg bg-white px-2"><img src="{{ asset('assets/img/mms.png') }}" alt="MKM Logo" class="w-full"></div><div><h1 class="text-lg font-bold leading-tight">MKM Scrap Delivery</h1><p class="mt-1 text-xs text-white/75">Goods Release</p></div></div></div>
<div class="px-5 py-4"><h2 class="text-lg font-bold">Sign in</h2><p class="mb-3 mt-1 text-sm text-muted">Use the internal account provided by your administrator.</p>@include('partials.alerts')
<form action="{{ route('auth.login') }}" method="POST" class="space-y-3" data-submit-once>@csrf
<div><label for="login">Email or username</label><input id="login" name="login" value="{{ old('login') }}" required autofocus autocomplete="username" placeholder="Your email or username"></div>
<div><label for="password">Password</label><div class="relative"><input id="password" name="password" type="password" required autocomplete="current-password" placeholder="Enter your password" class="!pr-24"><button type="button" class="absolute right-1 top-0.5 min-h-9 sm:min-h-7 px-3 text-xs font-semibold text-primary" data-password-toggle="password" aria-pressed="false">Show</button></div></div>
<label class="flex min-h-9 items-center gap-2 font-normal"><input name="remember" type="checkbox" value="1" @checked(old('remember'))>Remember me</label><button type="submit" class="btn w-full">Sign in</button>
</form>@if(\App\Http\Controllers\SsoController::configured())<div class="my-4 flex items-center gap-3"><span class="h-px flex-1 bg-border"></span><span class="text-xs text-muted">or</span><span class="h-px flex-1 bg-border"></span></div><a href="{{ route('auth.azure') }}" class="btn btn-secondary w-full">Sign in with MKM (SSO)</a>@endif<p class="mt-5 text-center text-xs leading-6 text-muted">Need an account or forgot your password?<br>Contact your MKM administrator.</p><p class="mt-5 border-t border-border pt-4 text-center text-[10px] leading-5 text-muted">© {{ now()->year }} PT Mitsubishi Krama Yudha Motors and Manufacturing</p></div>
</section>
</main>
<div class="no-print pb-4 text-center">@include('pwa.button')</div>
@include('pwa.help')
</body>
</html>
