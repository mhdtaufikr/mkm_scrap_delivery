@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<div class="page-heading"><div><h1 class="text-lg font-bold">Dashboard</h1><p class="help">Welcome, {{ auth()->user()->name }}.</p></div>@if(auth()->user()->hasPermission('documents.create'))<a class="btn" href="{{ route('documents.create') }}">+ New goods release</a>@endif</div>
@include('documents.filters')
<div class="stat-strip">
@foreach([['Total', $total], ['Open', $total - ($counts['COMPLETED'] ?? 0)], ['Close', $counts['COMPLETED'] ?? 0], ['Draft', $counts['DRAFT'] ?? 0]] as [$label, $value])
<div><span class="text-xs text-muted">{{ $label }}</span><strong>{{ number_format($value, 0, ',', '.') }}</strong></div>
@endforeach
</div>
<p class="mb-3 text-[11px] text-muted">Total = Open + Close · Drafts are included in Open · active filters apply.</p>
<div class="grid items-start gap-3 lg:grid-cols-[minmax(0,1fr)_260px]"><section class="card"><div class="card-header"><h2 class="text-xs font-bold">Recent documents</h2><a class="text-[11px] font-semibold text-primary" href="{{ route('documents.index', request()->only('q', 'status', 'type', 'queue')) }}">View all →</a></div>@include('documents.table')</section>
<section class="card"><div class="card-header"><h2 class="text-xs font-bold">Monthly Scrap Workflow</h2><span class="badge">{{ $approvalFlow ? 'v'.$approvalFlow['version'] : 'Not active' }}</span></div><ol class="space-y-2 p-3"><li class="text-[11px] font-semibold text-primary">Create and submit SMB</li>@foreach($approvalFlow['steps'] ?? [] as $step)<li class="flex items-start gap-2 text-[11px] leading-4"><span class="flex size-5 shrink-0 items-center justify-center rounded-full bg-primary-soft text-[9px] font-bold text-primary">{{ $loop->iteration }}</span><div>{{ __($step['name']) }}@if($step['delegate_to'])<span class="block text-[10px] text-muted">Delegation available</span>@endif</div></li>@endforeach</ol><div class="border-t border-border px-3 py-2 text-[10px] leading-4 text-muted">Documents retain the workflow version used at submission.</div></section></div>
@endsection
