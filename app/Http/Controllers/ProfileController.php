<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Facades\Socialite;

class ProfileController extends Controller
{
    public function refresh(Request $request)
    {
        abort_unless(SsoController::configured(), 503, 'SSO is not configured.');

        return Socialite::driver('azure')->redirect();
    }

    public function avatar(Request $request)
    {
        $path = $request->user()->avatar_path;
        abort_unless($path && str_starts_with($path, 'avatars/'.$request->user()->id.'/') && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}
