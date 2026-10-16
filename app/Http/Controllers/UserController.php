<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    private function admin(Request $request): void
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);
    }

    public function index(Request $request)
    {
        $this->admin($request);

        return view('users.index', ['users' => User::orderBy('name')->paginate(20)]);
    }

    public function store(Request $request)
    {
        $this->admin($request);
        $data = $request->validate([
            'name' => 'required|string|max:100', 'username' => 'required|alpha_dash|max:50|unique:users',
            'email' => 'required|email|max:255|unique:users', 'role' => ['required', Rule::exists('roles', 'code')->where('is_active', true)],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);
        User::create([...$data, 'is_active' => true, 'must_change_password' => true]);

        return back()->with('success', 'User created. The initial password must be changed on first login.');
    }

    public function update(Request $request, User $user)
    {
        $this->admin($request);
        abort_unless($user->access_status === 'approved', 422, 'Review this account through Access requests first.');
        foreach (['sso_tenant_id', 'sso_object_id'] as $key) {
            if (is_string($request->input($key))) {
                $request->merge([$key => strtolower(trim($request->input($key)))]);
            }
        }
        $data = $request->validate([
            'role' => ['required', Rule::exists('roles', 'code')->where('is_active', true)], 'is_active' => 'required|boolean',
            'local_login_enabled' => 'sometimes|boolean', 'sso_tenant_id' => 'nullable|required_with:sso_object_id|uuid',
            'section' => 'sometimes|nullable|string|max:255',
            'sso_object_id' => ['nullable', 'required_with:sso_tenant_id', 'uuid', Rule::unique('users', 'sso_object_id')->where('sso_tenant_id', is_string($request->input('sso_tenant_id')) ? $request->input('sso_tenant_id') : '')->ignore($user->id)],
        ]);
        foreach (['sso_tenant_id', 'sso_object_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = strtolower($data[$key]);
            }
        }
        if (array_key_exists('local_login_enabled', $data)) {
            $data['local_login_enabled'] = (bool) $data['local_login_enabled'];
        }
        abort_if($user->is($request->user()) && ($data['role'] !== $user->role || ! $data['is_active'] || (array_key_exists('local_login_enabled', $data) && ! $data['local_login_enabled'])), 422, 'You cannot disable your own role or login access.');
        $tenant = array_key_exists('sso_tenant_id', $data) ? $data['sso_tenant_id'] : $user->sso_tenant_id;
        $object = array_key_exists('sso_object_id', $data) ? $data['sso_object_id'] : $user->sso_object_id;
        abort_if(! ($data['local_login_enabled'] ?? $user->local_login_enabled) && (! $tenant || ! $object), 422, 'SSO-only access requires a Tenant ID and Object ID.');
        DB::transaction(function () use ($user, $data) {
            $user = User::lockForUpdate()->findOrFail($user->id);
            if (array_key_exists('section', $data) && $data['section'] !== $user->section) {
                $data['section_source'] = 'manual';
            }
            $user->fill($data);
            $revokeSessions = ! $user->is_active || $user->isDirty(['sso_tenant_id', 'sso_object_id', 'local_login_enabled']);
            if ($revokeSessions) {
                $user->remember_token = null;
            }
            $user->save();
            if ($revokeSessions) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
        });

        return back()->with('success', 'User access updated.');
    }

    public function resetPassword(Request $request, User $user)
    {
        $this->admin($request);
        abort_if($user->is($request->user()), 422, 'Use Change Password for your own account.');
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()]]);
        DB::transaction(function () use ($user, $data) {
            $user->update(['password' => $data['password'], 'must_change_password' => true, 'remember_token' => null]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        return back()->with('success', 'Temporary password saved. The user must change it on their next login.');
    }
}
