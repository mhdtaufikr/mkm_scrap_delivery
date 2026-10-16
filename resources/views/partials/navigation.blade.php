@php
$user = auth()->user();
$links = [
 ['dashboard', 'Dashboard', 'grid', []],
 ['documents.index', 'Goods Release', 'file', []],
 ['documents.index', 'My Tasks', 'clock', ['queue' => 1]],
];
$administration = [];
if($user->hasPermission('users.manage')) {
 $administration[] = ['access-requests.index', 'Access requests'];
 $administration[] = ['users.index', 'Users'];
 $administration[] = ['security-admin.index', 'Security access'];
}
if($user->hasPermission('roles.manage')) $administration[] = ['roles.index', 'Roles'];
if($user->hasPermission('approvals.manage')) $administration[] = ['approvals.index', 'Approval'];
@endphp
@foreach($links as [$route, $label, $icon, $query])
@php($active = request()->routeIs($route === 'documents.index' ? 'documents.*' : $route) && ($route !== 'documents.index' || (bool)request('queue') === (bool)($query['queue'] ?? false)))
<a href="{{ route($route, $query) }}" class="nav-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif><x-icon :name="$icon" class="!size-4" />{{ $label }}</a>
@endforeach
@if(count($administration))
@php($administrationActive = request()->routeIs('users.*', 'roles.*', 'approvals.*', 'access-requests.*', 'security-admin.*'))
<details class="relative" data-menu>
<summary class="nav-link h-full cursor-pointer {{ $administrationActive ? 'active' : '' }}"><x-icon name="flow" class="!size-4" />Administration<span class="ml-auto text-[10px]" aria-hidden="true">&#8964;</span></summary>
<div class="mt-1 grid rounded-lg border border-border bg-white p-1 shadow-lg xl:absolute xl:right-0 xl:z-50 xl:min-w-48">
@foreach($administration as [$route, $label])
@php($active = request()->routeIs(str_replace('.index', '.*', $route)))
<a href="{{ route($route) }}" class="flex min-h-10 items-center gap-2 rounded px-3 text-xs font-semibold hover:bg-primary-soft hover:text-primary {{ $active ? 'bg-primary-soft text-primary' : 'text-text-soft' }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</div>
</details>
@endif
