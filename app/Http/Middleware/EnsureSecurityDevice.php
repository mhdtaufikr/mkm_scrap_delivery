<?php

namespace App\Http\Middleware;

use App\Services\SecurityAccess;
use Closure;
use Illuminate\Http\Request;

class EnsureSecurityDevice
{
    public function handle(Request $request, Closure $next)
    {
        $device = app(SecurityAccess::class)->device($request);
        if (! $device) {
            return redirect()->route('security.activate')->withErrors(['device' => 'This browser is not activated, has expired, or its access was revoked. Ask an administrator to activate it.']);
        }
        $request->attributes->set('security_device', $device);
        if (! $device->last_used_at || $device->last_used_at->lt(now()->subMinutes(5))) {
            $device->update(['last_used_at' => now()]);
        }

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
