<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDocument;
use App\Models\User;
use App\Services\DocumentNotifications;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DriverSignatureController extends Controller
{
    public function show(string $token)
    {
        $document = $this->document($token);

        return response()->view('driver-signature.show', compact('document', 'token'))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, string $token)
    {
        $data = $request->validate([
            'driver_name' => 'required|string|min:3|max:150',
            'signature' => ['required', 'string', 'max:700000', 'regex:/^data:image\/png;base64,[A-Za-z0-9+\/=\r\n]+$/'],
            'confirmed' => 'required|accepted',
        ]);
        $binary = base64_decode(substr($data['signature'], strpos($data['signature'], ',') + 1), true);
        if ($binary === false || strlen($binary) < 100 || strlen($binary) > 500000 || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n")) {
            throw ValidationException::withMessages(['signature' => 'Please provide a valid signature.']);
        }

        $path = null;
        try {
            DB::transaction(function () use ($token, $data, $binary, &$path) {
                $document = DeliveryDocument::where('driver_token_hash', DeliveryDocument::driverTokenHash($token))->lockForUpdate()->firstOrFail();
                abort_unless($document->status === 'DRIVER_HANDOVER' && ! $document->driver_signed_at, 409, 'This signature link has already been used or is no longer active.');
                $path = 'driver-signatures/'.$document->id.'-'.bin2hex(random_bytes(8)).'.png';
                Storage::disk('local')->put($path, $binary);
                $document->forceFill([
                    'driver_signed_name' => trim($data['driver_name']),
                    'driver_signature_path' => $path,
                    'driver_signed_at' => now(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        $document = $this->document($token);
        app(DocumentNotifications::class)->send(
            User::whereIn('id', $document->pending_user_ids ?? [])->get(),
            $document,
            $document->driver_signed_name.' submitted the driver handover signature. MKM verification is ready.'
        );

        return redirect()->route('driver-signature.show', ['token' => $token])->with('success', 'Receipt and signature confirmed. Please return the phone to the MKM officer.');
    }

    public function qr(DeliveryDocument $document)
    {
        abort_unless(request()->user() && $document->actionableBy(request()->user()) && $document->status === 'DRIVER_HANDOVER', 403);
        $url = $document->driverSignatureUrl();
        abort_unless($url, 404);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(320, 2), new SvgImageBackEnd)))->writeString($url);

        return response($svg)->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'no-store, private');
    }

    public function image(DeliveryDocument $document)
    {
        abort_unless(request()->user() && $document->actionableBy(request()->user()) && $document->status === 'DRIVER_HANDOVER', 403);
        abort_unless($document->driver_signature_path && Storage::disk('local')->exists($document->driver_signature_path), 404);

        return Storage::disk('local')->response($document->driver_signature_path, $document->number.'-driver-signature.png', ['Cache-Control' => 'no-store, private']);
    }

    public function status(Request $request, DeliveryDocument $document)
    {
        abort_unless($document->status === 'DRIVER_HANDOVER' && $document->actionableBy($request->user()), 403);

        return response()->json([
            'signed' => (bool) $document->driver_signed_at,
            'signed_at' => $document->driver_signed_at?->toIso8601String(),
        ])->header('Cache-Control', 'no-store, private');
    }

    private function document(string $token): DeliveryDocument
    {
        abort_unless(strlen($token) === 64, 404);

        return DeliveryDocument::where('driver_token_hash', DeliveryDocument::driverTokenHash($token))->firstOrFail();
    }
}
