@extends('layouts.app')
@section('title', request('queue') ? 'My Tasks' : 'Goods Release')
@section('content')
<div class="page-heading"><div><h1 class="text-xl font-bold">{{ request('queue') ? 'My Tasks' : 'Goods Release' }}</h1><p class="help">{{ request('queue') ? 'Documents awaiting action from your role or assignment.' : 'Scrap and disposal documents.' }}</p></div>@if(auth()->user()->hasPermission('documents.create'))<a href="{{ route('documents.create') }}" class="btn">+ New goods release</a>@endif</div>
@include('documents.filters')
<div class="mb-3 flex flex-wrap items-center gap-3 text-xs"><span>Total: <strong>{{ $total }}</strong></span><span>Open: <strong>{{ $total - $closed }}</strong></span><span>Close: <strong>{{ $closed }}</strong></span><span class="text-muted">Total = Open + Close · filtered results</span></div>
<section class="card">@include('documents.table')<div class="p-3">{{ $documents->links() }}</div></section>
@endsection
