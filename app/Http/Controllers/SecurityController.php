<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\DocumentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    public function index()
    {
        return response()->view('security.index')->header('Cache-Control', 'no-store, private');
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['security_code' => ['required', 'string', 'min:12', 'max:20']]);
        $key = 'security-code:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['security_code' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        $hash = DeliveryDocument::securityCodeHash($data['security_code']);
        $document = DeliveryDocument::where('type', 'SCRAP')->where('security_code_hash', $hash)->first();
        if (! $document || $document->status !== 'SECURITY' || $document->security_code_used_at) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['security_code' => 'The unique number is invalid or the document is not ready for Security.']);
        }
        RateLimiter::clear($key);
        $request->session()->put('security_documents.'.$document->id, $hash);

        return redirect()->route('security.show', $document);
    }

    private function authorizeDocument(Request $request, DeliveryDocument $document): void
    {
        abort_unless($document->type === 'SCRAP', 404);
        $verified = $request->session()->get('security_documents.'.$document->id);
        abort_unless(is_string($verified) && $document->security_code_hash && hash_equals($document->security_code_hash, $verified), 403, 'Enter the document unique number first.');
    }

    public function show(Request $request, DeliveryDocument $document)
    {
        $this->authorizeDocument($request, $document);

        return response()->view('security.show', ['document' => $document->load('creator', 'events.user')])->header('Cache-Control', 'no-store, private');
    }

    public function evidence(Request $request, DeliveryDocument $document)
    {
        $this->authorizeDocument($request, $document);
        abort_unless($document->handover_path && Storage::disk('local')->exists($document->handover_path), 404);

        return Storage::disk('local')->download($document->handover_path, $document->number.'-handover.'.pathinfo($document->handover_path, PATHINFO_EXTENSION));
    }

    public function approve(Request $request, DeliveryDocument $document, DocumentWorkflow $workflow)
    {
        $this->authorizeDocument($request, $document);
        $data = $request->validate([
            'inspection_version' => 'required|string|size:64', 'security_name' => 'required|string|min:3|max:150',
            'goods_checked' => 'required|accepted', 'vehicle_checked' => 'required|accepted',
            'documents_checked' => 'required|accepted', 'notes' => 'nullable|string|max:1000',
        ]);
        DB::transaction(function () use ($request, $document, $workflow, $data) {
            $doc = DeliveryDocument::lockForUpdate()->findOrFail($document->id);
            $this->authorizeDocument($request, $doc);
            abort_unless(hash_equals($doc->inspectionVersion(), $data['inspection_version']), 409, 'The document changed after you opened it. Reload and inspect the latest version.');
            abort_unless($doc->status === 'SECURITY' && ! $doc->security_code_used_at, 409, 'This unique number has already been used or the document is no longer awaiting Security.');
            $actor = User::where('username', 'security.gate')->lockForUpdate()->firstOrFail();
            abort_unless($doc->actionableBy($actor) && isset($doc->actions()['release']), 403, 'This document is not ready for Security release.');
            $workflow->transition($doc, $actor, 'release', $data['notes'] ?? null, [], [
                'security_device_name' => 'Security PWA - unique number', 'security_officer_name' => trim($data['security_name']),
                'actual_actor_name' => trim($data['security_name']),
            ]);
            $doc->refresh()->update(['security_code_used_at' => now()]);
        });

        return redirect()->route('security.show', $document)->with('success', 'Goods release validated by '.trim($data['security_name']).'. The unique number is now closed.');
    }
}
