<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\ApprovalConfiguration;
use App\Services\DocumentWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PpcGateController extends Controller
{
    public function index()
    {
        return response()->view('ppc.index')->header('Cache-Control', 'no-store, private');
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['ppc_code' => ['required', 'string', 'size:6']]);
        $key = 'ppc-code:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['ppc_code' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }

        $hash = DeliveryDocument::ppcCodeHash($data['ppc_code']);
        $document = DeliveryDocument::where('type', 'DISPOSAL')
            ->where('status', 'DRAFT')
            ->where('ppc_code_hash', $hash)
            ->whereNull('ppc_code_used_at')
            ->first();
        if (! $document) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['ppc_code' => 'Kode PPC tidak ditemukan, sudah digunakan, atau dokumen bukan Disposal yang siap ditimbang.']);
        }

        RateLimiter::clear($key);
        $request->session()->put('ppc_documents.'.$document->id, $hash);

        return redirect()->route('ppc.show', $document);
    }

    private function authorizeDocument(Request $request, DeliveryDocument $document): void
    {
        abort_unless($document->type === 'DISPOSAL', 404);
        $verified = $request->session()->get('ppc_documents.'.$document->id);
        abort_unless(is_string($verified) && $document->ppc_code_hash && hash_equals($document->ppc_code_hash, $verified), 403, 'Masukkan kode PPC dokumen terlebih dahulu.');
    }

    public function show(Request $request, DeliveryDocument $document)
    {
        $this->authorizeDocument($request, $document);

        return response()->view('ppc.show', ['document' => $document])->header('Cache-Control', 'no-store, private');
    }

    public function weigh(Request $request, DeliveryDocument $document, DocumentWorkflow $workflow)
    {
        $this->authorizeDocument($request, $document);
        $data = $request->validate([
            'inspection_version' => 'required|string|size:64',
            'gross_weight' => 'required|numeric|min:0|max:999999999',
        ]);

        DB::transaction(function () use ($request, $document, $workflow, $data) {
            $doc = DeliveryDocument::lockForUpdate()->findOrFail($document->id);
            $this->authorizeDocument($request, $doc);
            abort_unless(hash_equals($doc->inspectionVersion(), $data['inspection_version']), 409, 'Data dokumen berubah. Muat ulang halaman lalu periksa kembali.');
            abort_unless($doc->type === 'DISPOSAL' && $doc->status === 'DRAFT' && ! $doc->ppc_code_used_at, 409, 'Dokumen sudah ditimbang atau tidak lagi menunggu PPC.');

            $details = $doc->form_details ?? [];
            $netWeight = $details['net_weight'] ?? null;
            if (! is_numeric($netWeight)) {
                throw ValidationException::withMessages(['gross_weight' => 'Berat bersih dokumen belum tersedia.']);
            }
            if ((float) $data['gross_weight'] < (float) $netWeight) {
                throw ValidationException::withMessages(['gross_weight' => 'Berat kotor harus sama dengan atau lebih besar dari berat bersih.']);
            }

            $definition = app(ApprovalConfiguration::class)->definition($doc);
            $startCode = data_get($definition, 'start_code');
            $firstApprovers = data_get($definition, 'steps.'.$startCode.'.user_ids', []);
            $fisty = User::where('username', 'mkm02119')->where('access_status', 'approved')->where('is_active', true)->first();
            if (! $fisty || ! in_array($fisty->id, $firstApprovers, true)) {
                throw ValidationException::withMessages(['gross_weight' => 'Workflow approval Disposal belum diarahkan ke Fisty. Hubungi administrator.']);
            }

            $details['gross_weight'] = $data['gross_weight'];
            $doc->update(['form_details' => $details, 'ppc_code_used_at' => now()]);
            $workflow->transition($doc, $doc->creator()->firstOrFail(), 'submit', 'Berat kotor diinput melalui PPC Gate.');
        });

        return redirect()->route('ppc.show', $document)->with('success', 'Berat kotor tersimpan. Dokumen sudah dikirim ke Fisty untuk proses approval.');
    }
}
