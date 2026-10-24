<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\DocumentWorkflow;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SecurityController extends Controller
{
    public function index()
    {
        return response()->view('security.index', $this->dashboardData())->header('Cache-Control', 'no-store, private');
    }

    public function dashboard()
    {
        $data = $this->dashboardData();

        return response()->json([
            'html' => view('security.partials.live-dashboard', $data)->render(),
            'count' => $data['documents']->count(),
            'refreshed_at' => now()->format('H:i:s'),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function dashboardData(): array
    {
        $documents = DeliveryDocument::query()
            ->whereIn('type', ['SCRAP', 'DISPOSAL'])
            ->whereIn('status', ['DRIVER_HANDOVER'])
            ->with(['events' => fn ($query) => $query
                ->where('from_status', 'HRA_APPROVAL')
                ->where('action', 'approve')])
            ->latest('updated_at')
            ->limit(30)
            ->get();

        $pendingDocuments = DeliveryDocument::query()
            ->whereIn('type', ['SCRAP', 'DISPOSAL'])
            ->whereIn('status', ['SALES_APPROVAL', 'HRA_APPROVAL'])
            ->latest('updated_at')
            ->limit(30)
            ->get();

        return [
            'documents' => $documents,
            'pendingDocuments' => $pendingDocuments,
            'counts' => [
                'pending' => $pendingDocuments->count(),
                'call' => $documents->count(),
                'handover' => $documents->whereNull('driver_signed_at')->count(),
                'gate' => $documents->whereNotNull('driver_signed_at')->count(),
            ],
        ];
    }

    public function lookup(Request $request)
    {
        $data = $request->validate(['security_code' => ['required', 'string', 'min:12', 'max:32']]);
        $key = 'security-code:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['security_code' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.']);
        }
        $hash = DeliveryDocument::securityCodeHash($data['security_code']);
        $document = DeliveryDocument::whereIn('type', ['SCRAP', 'DISPOSAL'])->where('security_code_hash', $hash)->first();
        if (! $document || ! in_array($document->status, ['SALES_APPROVAL', 'HRA_APPROVAL', 'DRIVER_HANDOVER'], true) || $document->security_code_used_at) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['security_code' => 'Nomor unik tidak ditemukan atau dokumen belum siap diperiksa Security.']);
        }
        RateLimiter::clear($key);
        $request->session()->put('security_documents.'.$document->id, $hash);

        return redirect()->route('security.show', $document);
    }

    private function authorizeDocument(Request $request, DeliveryDocument $document): void
    {
        abort_unless(in_array($document->type, ['SCRAP', 'DISPOSAL'], true), 404);
        $verified = $request->session()->get('security_documents.'.$document->id);
        abort_unless(is_string($verified) && $document->security_code_hash && hash_equals($document->security_code_hash, $verified), 403, 'Masukkan nomor unik dokumen terlebih dahulu.');
    }

    public function show(Request $request, DeliveryDocument $document)
    {
        $this->authorizeDocument($request, $document);
        $driverQrDataUri = null;
        if ($document->status === 'DRIVER_HANDOVER' && ! $document->driver_signed_at && $document->driverSignatureUrl()) {
            $svg = (new Writer(new ImageRenderer(new RendererStyle(280, 2), new SvgImageBackEnd)))->writeString($document->driverSignatureUrl());
            $driverQrDataUri = 'data:image/svg+xml;base64,'.base64_encode($svg);
        }

        return response()->view('security.show', ['document' => $document->load('creator', 'events.user'), 'driverQrDataUri' => $driverQrDataUri])->header('Cache-Control', 'no-store, private');
    }

    public function evidence(Request $request, DeliveryDocument $document)
    {
        $this->authorizeDocument($request, $document);
        abort_unless($document->handover_path && Storage::disk('local')->exists($document->handover_path), 404);

        return Storage::disk('local')->download($document->handover_path, $document->number.'-handover.'.pathinfo($document->handover_path, PATHINFO_EXTENSION));
    }

    public function status(Request $request, DeliveryDocument $document)
    {
        $this->authorizeDocument($request, $document);

        return response()->json([
            'status' => $document->status,
            'signed' => (bool) $document->driver_signed_at,
        ])->header('Cache-Control', 'no-store, private');
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
            abort_unless(hash_equals($doc->inspectionVersion(), $data['inspection_version']), 409, 'Data dokumen berubah. Muat ulang halaman lalu periksa kembali.');
            abort_unless($doc->status === 'DRIVER_HANDOVER' && $doc->driver_signed_at && ! $doc->security_code_used_at, 409, 'Tanda tangan pengemudi belum tersedia atau nomor unik sudah ditutup.');
            $actor = User::where('username', 'security.gate')->lockForUpdate()->firstOrFail();
            abort_unless($doc->actionableBy($actor) && isset($doc->actions()['release']), 403, 'Dokumen belum siap diselesaikan oleh Security.');
            $workflow->transition($doc, $actor, 'release', $data['notes'] ?? null, [], [
                'security_device_name' => 'Security PWA - unique number', 'security_officer_name' => trim($data['security_name']),
                'actual_actor_name' => trim($data['security_name']),
            ]);
            $doc->refresh()->update(['security_code_used_at' => now()]);
        });

        return redirect()->route('security.show', $document)->with('success', 'Pemeriksaan selesai oleh '.trim($data['security_name']).'. Nomor unik sudah ditutup.');
    }
}
