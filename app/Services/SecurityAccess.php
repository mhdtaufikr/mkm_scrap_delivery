<?php

namespace App\Services;

use App\Models\SecurityDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SecurityAccess
{
    public const COOKIE = 'mkm_security_device';

    public function device(Request $request, bool $lock = false): ?SecurityDevice
    {
        $credential = $request->cookie(self::COOKIE);
        if (! is_string($credential) || ! preg_match('/^(\d+)\.([a-zA-Z0-9]{64})$/D', $credential, $parts)) {
            return null;
        }
        $query = SecurityDevice::whereKey($parts[1]);
        if ($lock) {
            $query->lockForUpdate();
        }$device = $query->first();
        if (! $device || ! $device->is_active || ! $device->token_hash || ! $device->token_expires_at?->isFuture() || ! hash_equals($device->token_hash, hash('sha256', $parts[2]))) {
            return null;
        }

        return $device;
    }

    public function activation(SecurityDevice $device): string
    {
        $code = strtoupper(Str::random(12));
        $device->update(['activation_hash' => hash('sha256', $code), 'activation_expires_at' => now()->addMinutes(15), 'token_hash' => null, 'token_expires_at' => null, 'is_active' => true]);

        return $code;
    }
}
