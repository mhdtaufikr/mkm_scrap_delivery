<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->view('auth.login')->header('Cache-Control', 'no-store, private');
    }

    public function authenticate(Request $request)
    {
        $data = $request->validate(['login' => 'required|string|max:255', 'password' => 'required|string', 'remember' => 'nullable|boolean']);
        $key = Str::lower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['login' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }
        $field = filter_var($data['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        if (! Auth::attempt([$field => $data['login'], 'password' => $data['password'], 'is_active' => true, 'local_login_enabled' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['login' => 'Incorrect email/username or password, or the account is inactive.']);
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        if (! $request->user()->roleRecord?->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            throw ValidationException::withMessages(['login' => 'The account role is inactive. Contact your administrator.']);
        }
        $request->session()->put('auth_method', 'local');
        $request->user()->update(['last_login_at' => now()]);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'You have signed out.');
    }

    public function password()
    {
        abort_unless(auth()->user()->local_login_enabled, 403, 'SSO passwords are managed by Microsoft.');

        return view('auth.password');
    }

    public function updatePassword(Request $request)
    {
        abort_unless($request->user()->local_login_enabled, 403);
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => ['required', 'confirmed', 'different:current_password', Password::min(10)->mixedCase()->numbers()]]);
        $request->user()->update(['password' => $data['password'], 'must_change_password' => false, 'remember_token' => Str::random(60)]);
        Auth::logoutOtherDevices($data['password']);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Password updated.');
    }
}
