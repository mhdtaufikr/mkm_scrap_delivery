<?php

namespace App\Http\Controllers;

use App\Models\ApprovalFlow;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovalConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApprovalMasterController extends Controller
{
    private function authorizeMaster(Request $request): void
    {
        abort_unless($request->user()->hasPermission('approvals.manage'), 403);
    }

    public function index(Request $request)
    {
        $this->authorizeMaster($request);

        return view('masters.approvals', ['flows' => ApprovalFlow::withCount('steps')->orderByDesc('is_active')->get()]);
    }

    public function store(Request $request)
    {
        $this->authorizeMaster($request);
        $data = $request->validate(['name' => 'required|string|max:150']);
        $flow = ApprovalFlow::create([...$data, 'type' => 'SCRAP']);

        return redirect()->route('approvals.edit', $flow)->with('success', 'Workflow draft created. Add steps before publishing.');
    }

    public function edit(Request $request, ApprovalFlow $flow)
    {
        $this->authorizeMaster($request);

        return view('masters.approval-edit', ['flow' => $flow->load('steps'), 'roles' => Role::options(), 'users' => User::where('is_active', true)->orderBy('name')->get()]);
    }

    public function update(Request $request, ApprovalFlow $flow)
    {
        $this->authorizeMaster($request);
        $data = $request->validate(['name' => 'required|string|max:150', 'start_code' => 'nullable|string|max:50', 'draft_notice_roles' => 'nullable|array', 'draft_notice_roles.*' => [Rule::exists('roles', 'code')->where('is_active', true)]]);
        DB::transaction(function () use ($flow, $data) {
            $flow = ApprovalFlow::lockForUpdate()->findOrFail($flow->id);
            $flow->update([...$data, 'draft_notice_roles' => $data['draft_notice_roles'] ?? []]);
        });

        return back()->with('success', 'Draft settings saved. Publish to apply to new submissions.');
    }

    public function saveStep(Request $request, ApprovalFlow $flow)
    {
        $this->authorizeMaster($request);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z][A-Z0-9_]{1,49}$/', Rule::notIn(['DRAFT', 'COMPLETED'])],
            'name' => 'required|string|max:150', 'position' => 'required|integer|min:1|max:30',
            'actor_mode' => 'required|in:role,users,owner',
            'role_code' => ['nullable', 'required_if:actor_mode,role', Rule::exists('roles', 'code')->where('is_active', true)],
            'user_ids' => 'nullable|array|required_if:actor_mode,users', 'user_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('is_active', true)],
            'action' => 'required|in:next,approve,handover,release', 'action_label' => 'required|string|max:100',
            'next_code' => 'required|string|max:50', 'delegate_to' => 'nullable|string|max:50',
            'notice_roles' => 'nullable|array', 'notice_roles.*' => [Rule::exists('roles', 'code')->where('is_active', true)],
            'delegate_notice_roles' => 'nullable|array', 'delegate_notice_roles.*' => [Rule::exists('roles', 'code')->where('is_active', true)],
            'allow_return' => 'required|boolean',
        ]);
        DB::transaction(function () use ($flow, $data) {
            $flow = ApprovalFlow::lockForUpdate()->findOrFail($flow->id);
            abort_if(! $flow->steps()->where('code', $data['code'])->exists() && $flow->steps()->count() >= 30, 422, 'Up to 30 steps are allowed.');
            $flow->steps()->updateOrCreate(['code' => $data['code']], [...$data, 'user_ids' => array_values(array_unique(array_map('intval', $data['user_ids'] ?? []))), 'notice_roles' => $data['notice_roles'] ?? [], 'delegate_notice_roles' => $data['delegate_notice_roles'] ?? []]);
            $flow->touch();
        });

        return back()->with('success', 'Step draft saved. Publish to activate the changes.');
    }

    public function deleteStep(Request $request, ApprovalFlow $flow, int $step)
    {
        $this->authorizeMaster($request);
        DB::transaction(function () use ($flow, $step) {
            $flow = ApprovalFlow::lockForUpdate()->findOrFail($flow->id);
            $flow->steps()->findOrFail($step)->delete();
            $flow->touch();
        });

        return back()->with('success', 'Step removed from the draft. Published versions are retained.');
    }

    public function publish(Request $request, ApprovalFlow $flow, ApprovalConfiguration $config)
    {
        $this->authorizeMaster($request);
        $config->publish($flow, $request->user());

        return back()->with('success', 'Workflow published for new submissions. Existing documents retain their previous version.');
    }
}
