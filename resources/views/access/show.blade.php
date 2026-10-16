<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Request access | MKM Scrap Delivery</title>@vite(['resources/css/app.css','resources/js/app.js'])@include('pwa.head')
</head>
<body class="bg-slate-50"><main class="mx-auto max-w-xl px-3 py-6"><div class="panel p-4"><img src="{{ asset('assets/img/mms.png') }}" alt="MKM" class="mb-3 h-8"><h1 class="text-lg font-bold">MKM Scrap Delivery access</h1><p class="mt-1 text-xs text-muted">Signed in with Microsoft as {{ auth()->user()->name }} &middot; {{ auth()->user()->email }}</p>
@include('partials.alerts')
<dl class="my-3 grid grid-cols-[90px_1fr] gap-1 text-xs"><dt>Job title</dt><dd>{{ auth()->user()->job_title ?: 'Not provided' }}</dd><dt>Department</dt><dd>{{ auth()->user()->department ?: 'Not provided' }}</dd><dt>Section</dt><dd>{{ auth()->user()->section ?: 'Not provided' }}</dd></dl>
@if($latest)<div class="my-3 rounded border border-slate-200 p-3 text-xs"><p class="font-bold">Request #{{ $latest->id }} &middot; {{ ucfirst($latest->status) }}</p><p>Requested role: {{ __($latest->requestedRole?->name ?? $latest->requested_role) }}</p><p>Submitted: {{ $latest->created_at->format('d M Y H:i') }}</p>@if($latest->review_note)<p class="mt-1 whitespace-pre-wrap">Reviewer note: {{ $latest->review_note }}</p>@endif</div>@endif
@if(auth()->user()->access_status === 'pending')<h2 class="text-sm font-semibold">Waiting for administrator approval</h2><p class="mt-1 text-xs text-muted">Your account has been created. Application access is restricted until approval. We will email you when your request is reviewed.</p><a class="btn btn-secondary mt-3" href="{{ route('access.show') }}">Check status</a>
@else<h2 class="text-sm font-semibold">{{ $latest ? 'Submit a revised request' : 'Tell us what access you need' }}</h2><p class="mt-1 text-xs text-muted">Choose the role that matches your work. Your administrator must approve it before access is enabled.</p>
<form method="POST" action="{{ route('access.store') }}" class="mt-3 grid gap-2">@csrf
<label for="requested_role">Requested role</label><select id="requested_role" name="requested_role" required><option value="">Select a role</option>@foreach($roles as $role)<option value="{{ $role->code }}" @selected(old('requested_role',$latest?->requested_role) === $role->code)>{{ __($role->name) }}</option>@endforeach</select>
<label for="responsibility">Your responsibilities / acting as</label><input id="responsibility" name="responsibility" maxlength="255" required value="{{ old('responsibility',$latest?->responsibility) }}" placeholder="Example: PPC receiving officer for scrap deliveries">
<label for="reason">Business reason</label><textarea id="reason" name="reason" required minlength="10" maxlength="2000" rows="3" placeholder="Describe why you need access and which work you will perform.">{{ old('reason',$latest?->reason) }}</textarea><button class="btn btn-primary" type="submit">Submit access request</button></form>@endif
</div></main><div class="no-print pb-4 text-center">@include('pwa.button')</div>
@include('pwa.help')
</body></html>
