<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class EnsureAccountReady
{
    public function handle(Request $request, Closure $next)
    {
        $routeName = (string) ($request->route()?->getName() ?? $request->route()?->getAction('legacy_name'));
        if (! $request->user()->is_active || ! $request->user()->roleRecord?->is_active || (! $request->user()->local_login_enabled && $request->session()->get('auth_method') !== 'sso')) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['login' => 'Account is inactive. Contact your administrator.']);
        }
        if ($request->user()->access_status !== 'approved' && ! Str::is(['access.*', 'profile.*', 'logout'], $routeName)) {
            return redirect()->route('access.show');
        }
        if ($request->user()->must_change_password && $request->session()->get('auth_method') !== 'sso' && ! Str::is(['password.*', 'logout'], $routeName)) {
            return redirect()->route('password.edit');
        }

        return $next($request);
    }
}
