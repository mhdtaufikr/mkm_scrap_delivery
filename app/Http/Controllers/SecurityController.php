<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDocument;
use App\Models\SecurityDevice;
use App\Models\SecurityOfficer;
use App\Models\User;
use App\Services\DocumentWorkflow;
use App\Services\SecurityAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    public function activation(Request $request, SecurityAccess $access)
    {
        if ($access->device($request)) {
            return redirect()->route('security.index');
        }

        return response()->view('security.activate')->header('Cache-Control', 'no-store, private');
    }

    public function pair(Request $request)
    {
        $data = $request->validate(['activation_code' => 'required|string|size:12']);
        $code = strtoupper(trim($data['activation_code']));
        $credential = DB::transaction(function () use ($code) {
            $d = SecurityDevice::where('activation_hash', hash('sha256', $code))->lockForUpdate()->first();
            if (! $d || ! $d->is_active || ! $d->activation_expires_at?->isFuture()) {
                throw ValidationException::withMessages(['activation_code' => 'The activation code is invalid, expired, or already used.']);
            }
            $token = Str::random(64);
            $d->update(['token_hash' => hash('sha256', $token), 'token_expires_at' => now()->addDays(90), 'activation_hash' => null, 'activation_expires_at' => null, 'paired_at' => now(), 'last_used_at' => now()]);

            return $d->id.'.'.$token;
        });
        Cookie::queue(cookie(SecurityAccess::COOKIE, $credential, 90 * 24 * 60, '/', null, $request->isSecure(), true, false, 'strict'));
        $request->session()->regenerate();

        return redirect()->route('security.index')->with('success', 'Security browser activated. Select your name and enter your PIN only when approving goods release.');
    }

    private function scope(DeliveryDocument $document): void
    {
        abort_unless($document->type === 'SCRAP', 404);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100']);
        $q = DeliveryDocument::where('type', 'SCRAP');
        if ($data['q'] ?? null) {
            $q->where(fn ($query) => $query->where('number', 'like', '%'.$data['q'].'%')->orWhere('plate_number', 'like', '%'.$data['q'].'%'));
        } else {
            $q->where('status', '!=', 'COMPLETED');
        }

        return view('security.index', ['documents' => $q->latest('id')->paginate(12)->withQueryString()]);
    }

    public function show(Request $request, DeliveryDocument $document)
    {
        $this->scope($document);
        $officers = SecurityOfficer::with('user.roleRecord')->where('is_active', true)->get()->filter(fn ($o) => $o->eligible());

        return view('security.show', ['document' => $document->load('events.user'), 'officers' => $officers]);
    }

    public function evidence(DeliveryDocument $document)
    {
        $this->scope($document);
        abort_unless($document->handover_path && Storage::disk('local')->exists($document->handover_path), 404);

        return Storage::disk('local')->download($document->handover_path, $document->number.'-handover.'.pathinfo($document->handover_path, PATHINFO_EXTENSION));
    }

    public function approve(Request $request, DeliveryDocument $document, SecurityAccess $access, DocumentWorkflow $workflow)
    {
        $this->scope($document);
        $data = $request->validate(['inspection_version' => 'required|string|size:64', 'officer_id' => 'required|integer', 'pin' => ['required', 'string', 'regex:/^[0-9]{6}$/D'], 'goods_checked' => 'required|accepted', 'vehicle_checked' => 'required|accepted', 'notes' => 'nullable|string|max:1000']);
        $key = 'security-pin:'.$data['officer_id'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['pin' => 'Too many incorrect PIN attempts. Try again in '.RateLimiter::availableIn($key).' seconds or contact an administrator.']);
        }
        $officer = SecurityOfficer::with('user.roleRecord')->find($data['officer_id']);
        if (! $officer || ! $officer->eligible() || ! Hash::check($data['pin'], $officer->pin_hash)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['pin' => 'Invalid officer or PIN.']);
        }
        DB::transaction(function () use ($request, $document, $access, $workflow, $officer, $data) {
            $device = $access->device($request, true);
            abort_unless($device, 403, 'Security device access was revoked.');
            $officer = SecurityOfficer::lockForUpdate()->findOrFail($officer->id);
            $user = User::lockForUpdate()->findOrFail($officer->user_id);
            $officer->setRelation('user', $user);
            abort_unless($officer->eligible() && Hash::check($data['pin'], $officer->pin_hash), 403, 'Officer access or PIN has changed.');
            $doc = DeliveryDocument::lockForUpdate()->findOrFail($document->id);
            abort_unless(hash_equals($doc->inspectionVersion(), $data['inspection_version']), 409, 'The document changed after you opened it. Reload and inspect the latest version.');
            abort_unless($doc->actionableBy($user) && isset($doc->actions()['release']), 403, 'This document is not ready for Security release or has already been released.');
            $workflow->transition($doc, $user, 'release', $data['notes'] ?? null, [], ['security_device_id' => $device->id, 'security_device_name' => $device->name, 'security_officer_name' => $user->name]);
        });
        RateLimiter::clear($key);

        return redirect()->route('security.show', $document)->with('success', 'Goods release approved by '.$officer->user->name.'. The approval time and Security device have been recorded.');
    }
}
