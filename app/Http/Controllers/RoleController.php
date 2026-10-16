<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('roles.manage'), 403);

        return view('masters.roles', ['roles' => Role::orderBy('name')->get()]);
    }

    private function validated(Request $request): array
    {
        return $request->validate(['name' => 'required|string|max:100', 'permissions' => 'nullable|array', 'permissions.*' => ['string', Rule::in(array_keys(Role::PERMISSIONS))], 'is_active' => 'required|boolean']);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->hasPermission('roles.manage'), 403);
        $data = $this->validated($request);
        $code = $request->validate(['code' => ['required', 'regex:/^[A-Z][A-Z0-9_]{1,49}$/', 'unique:roles,code']])['code'];
        Role::create([...$data, 'code' => $code, 'permissions' => $data['permissions'] ?? []]);

        return back()->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role)
    {
        abort_unless($request->user()->hasPermission('roles.manage'), 403);
        $data = $this->validated($request);
        $data['permissions'] = $data['permissions'] ?? [];
        abort_if($role->code === 'ADMIN' && (! $data['is_active'] || array_diff(['users.manage', 'roles.manage', 'approvals.manage'], $data['permissions'])), 422, 'The ADMIN role must remain active with user and master access.');
        abort_if(! $data['is_active'] && User::where('role', $role->code)->where('is_active', true)->exists(), 422, 'Deactivate or reassign active users in this role first.');
        abort_if($request->user()->role === $role->code && ! in_array('roles.manage', $data['permissions']), 422, 'You cannot revoke your own role-management access.');
        $role->update($data);

        return back()->with('success', 'Role and permissions updated. Role codes are retained for document history.');
    }
}
