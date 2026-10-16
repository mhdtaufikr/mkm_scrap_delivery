<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AccessRequestMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccessRequestController extends Controller
{
    public function show(Request $request)
    {
        if ($request->user()->access_status === 'approved') {
            return redirect()->route('dashboard');
        }

        return view('access.show', ['latest' => AccessRequest::where('user_id', $request->user()->id)->latest('id')->first(), 'roles' => Role::where('is_active', true)->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->session()->get('auth_method') === 'sso', 403);
        $data = $request->validate(['requested_role' => ['required', Rule::exists('roles', 'code')->where('is_active', true)], 'responsibility' => 'required|string|max:255', 'reason' => 'required|string|min:10|max:2000']);
        DB::transaction(function () use ($request, $data) {
            $u = User::lockForUpdate()->findOrFail($request->user()->id);
            abort_unless(in_array($u->access_status, ['onboarding', 'rejected'], true), 409, 'You already have a pending request or approved access.');
            $admins = User::where('access_status', 'approved')->where('is_active', true)->whereHas('roleRecord', fn ($q) => $q->where('is_active', true))->get()->filter(fn ($a) => $a->hasPermission('users.manage') && ! str_ends_with($a->email, '.local') && ! $a->is($u));
            abort_if($admins->isEmpty(), 422, 'No access administrator is available. Contact your IT administrator.');
            $r = AccessRequest::create([...$data, 'user_id' => $u->id, 'profile' => $u->only(['name', 'email', 'job_title', 'department', 'section'])]);
            $u->update(['access_status' => 'pending']);
            foreach ($admins as $admin) {
                $admin->notify(new AccessRequestMail($r, true));
            }
        });

        return redirect()->route('access.show')->with('success', 'Request submitted. An administrator will review your access.');
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);
        $query = AccessRequest::with(['user', 'requestedRole', 'reviewer'])->latest('id');
        if ($request->filled('request')) {
            $query->whereKey($request->integer('request'));
        }

        return view('access.index', ['requests' => $query->paginate(15), 'pendingCount' => AccessRequest::where('status', 'pending')->count()]);
    }

    public function decide(Request $request, AccessRequest $accessRequest)
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'review_note' => 'nullable|required_if:decision,rejected|string|max:2000']);
        DB::transaction(function () use ($request, $accessRequest, $data) {
            // Use the same user-first locking order as submission.
            $u = User::lockForUpdate()->findOrFail($accessRequest->user_id);
            $r = AccessRequest::lockForUpdate()->findOrFail($accessRequest->id);
            abort_if($u->is($request->user()), 403, 'You cannot review your own access request.');
            abort_unless($r->status === 'pending' && $u->access_status === 'pending', 409, 'This request has already been reviewed.');
            abort_unless($u->is_active, 422, 'This account is suspended.');
            if ($data['decision'] === 'approved') {
                abort_unless(Role::where('code', $r->requested_role)->where('is_active', true)->exists(), 422, 'The requested role is inactive. Reject this request so the user can select another role.');
                $u->role = $r->requested_role;
            }
            $u->access_status = $data['decision'];
            $u->save();
            $r->update(['status' => $data['decision'], 'review_note' => $data['review_note'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $u->notify(new AccessRequestMail($r));
        });

        return redirect()->route('access-requests.index')->with('success', 'Access request '.$data['decision'].'. The user notification is queued.');
    }
}
