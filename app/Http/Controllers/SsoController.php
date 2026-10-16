<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\MicrosoftProfileSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class SsoController extends Controller
{
    public static function configured(): bool
    {
        return config('services.azure.enabled') && Str::isUuid((string) config('services.azure.tenant'))
            && filled(config('services.azure.client_id')) && filled(config('services.azure.client_secret')) && filled(config('services.azure.redirect'));
    }

    public function redirect(Request $request)
    {
        if (! self::configured()) {
            return redirect()->route('login')->withErrors(['sso' => 'SSO is not enabled. Use local login or contact your administrator.']);
        }

        $provider = Socialite::driver('azure');
        if ($request->boolean('select_account')) {
            $provider->with(['prompt' => 'select_account']);
        }

        return $provider->redirect();
    }

    public function callback(Request $request, MicrosoftProfileSync $profiles)
    {
        if (! self::configured()) {
            return redirect()->route('login')->withErrors(['sso' => 'SSO configuration is incomplete.']);
        }
        try {
            // Stateful authorization-code flow: Socialite validates the session state before exchanging the code.
            // The provider fetches identity from Microsoft Graph with the access token from the pinned tenant.
            $identity = Socialite::driver('azure')->user();
            $objectId = (string) $identity->getId();
            if (! Str::isUuid($objectId)) {
                throw new \UnexpectedValueException('Invalid directory identity.');
            }
            $current = $request->user();
            if ($current && (strtolower((string) $current->sso_tenant_id) !== strtolower(config('services.azure.tenant')) || strtolower((string) $current->sso_object_id) !== strtolower($objectId))) {
                // End the previous application session before starting a fresh, stateful sign-in.
                // Do not bind or provision the different identity during a profile refresh.
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('auth.azure', ['select_account' => 1]);
            }
            $user = User::where('sso_tenant_id', strtolower(config('services.azure.tenant')))->where('sso_object_id', strtolower($objectId))->first();
            if (! $user) {
                $principal = strtolower((string) ($identity->getRaw()['userPrincipalName'] ?? ''));
                $tenant = strtolower(config('services.azure.tenant'));
                $user = DB::transaction(function () use ($principal, $tenant, $objectId) {
                    // Only explicitly pre-enrolled accounts may bind once using the Graph principal.
                    // Existing email matches never inherit permissions through automatic linking.
                    $candidate = User::where('email', $principal)->where('sso_tenant_id', $tenant)->where('sso_link_pending', true)->whereNull('sso_object_id')->lockForUpdate()->first();
                    if (! $candidate) {
                        if (! filter_var($principal, FILTER_VALIDATE_EMAIL) || User::where('email', $principal)->exists()) {
                            return null;
                        }

                        return User::firstOrCreate(
                            ['sso_tenant_id' => $tenant, 'sso_object_id' => strtolower($objectId)],
                            ['email' => $principal, 'name' => $principal, 'password' => Str::random(64), 'role' => 'VIEWER',
                                'access_status' => 'onboarding', 'is_active' => true, 'local_login_enabled' => false]
                        );
                    }
                    if (! $candidate->is_active || ! $candidate->roleRecord?->is_active) {
                        return null;
                    }
                    $candidate->update(['sso_object_id' => strtolower($objectId), 'sso_link_pending' => false]);

                    return $candidate;
                });
            }
            if (! $user || ! $user->is_active || ! $user->roleRecord?->is_active) {
                return redirect()->route('login')->withErrors(['sso' => 'The SSO account is not authorized or is inactive. Contact your administrator.']);
            }
            $profiles->sync($user, $identity);
            Auth::login($user, false);
            $request->session()->regenerate();
            $request->session()->put('auth_method', 'sso');
            $user->update(['last_login_at' => now()]);

            if ($user->access_status !== 'approved') {
                $request->session()->forget('url.intended');

                return redirect()->route('access.show');
            }

            return redirect()->intended(route('dashboard'));
        } catch (\Throwable $exception) {
            // Never log provider exception messages, authorization codes, access tokens, or secrets.
            Log::warning('SSO login failed.', ['exception_class' => $exception::class]);

            return redirect()->route('login')->withErrors(['sso' => 'SSO sign-in failed or the session expired. Restart using the SSO sign-in button.']);
        }
    }
}
