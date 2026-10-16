@extends('layouts.app')
@section('title', 'Approval Workflows')
@section('content')
<div class="page-heading"><div><h1 class="text-xl font-bold">Approval Workflows</h1><p class="help">Configure steps and approvers. Publishing activates a new version for new submissions.</p></div></div>
<form method="POST" action="{{ route('approvals.store') }}" class="card mb-3 flex flex-wrap items-end gap-3 p-3">@csrf<div class="min-w-56 flex-1"><label for="name">New Monthly Scrap workflow name</label><input name="name" id="name" placeholder="Approval workflow name" required maxlength="150"></div><button class="btn" type="submit">+ Create workflow draft</button></form>
<div class="card table-wrap"><table class="data-table"><thead><tr><th>Flow</th><th>Type</th><th>Draft steps</th><th>Published version</th><th>Status</th><th>Settings</th></tr></thead><tbody>@foreach($flows as $flow)<tr><td class="font-semibold">{{ __($flow->name) }}</td><td>{{ $flow->type }}</td><td>{{ $flow->steps_count }}</td><td>{{ $flow->version ?: '—' }}</td><td><span class="badge {{ !$flow->is_active ? 'badge-draft' : '' }}">{{ $flow->is_active ? 'Active' : 'Draft / Inactive' }}</span></td><td><a class="btn btn-secondary" href="{{ route('approvals.edit', $flow) }}">Configure →</a></td></tr>@endforeach</tbody></table></div>
<p class="help mt-4">Disposal workflows are not available yet. The existing workflow reference and settlement process are still required.</p>
@endsection
