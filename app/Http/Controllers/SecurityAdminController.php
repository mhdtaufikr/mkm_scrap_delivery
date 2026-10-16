<?php

namespace App\Http\Controllers;

use App\Models\SecurityDevice;
use App\Models\SecurityOfficer;
use App\Models\User;
use App\Services\SecurityAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SecurityAdminController extends Controller
{
    private function admin(Request $request): void
    {
        abort_unless($request->user()->hasPermission('users.manage'), 403);
    }

    public function index(Request $request)
    {
        $this->admin($request);

        return response()->view('security.admin', ['devices' => SecurityDevice::latest('id')->get(), 'officers' => SecurityOfficer::with('user')->orderBy('id')->get()])->header('Cache-Control', 'no-store, private');
    }

    public function device(Request $request, SecurityAccess $access)
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:100']);
        $code = DB::transaction(function () use ($data, $request, $access) {
            $d = SecurityDevice::create([...$data, 'created_by' => $request->user()->id]);

            return $access->activation($d);
        });

        return back()->with('activation_code', $code)->with('success', 'Device created. Enter the one-time code on the Security browser within 15 minutes.');
    }

    public function resetDevice(Request $request, SecurityDevice $device, SecurityAccess $access)
    {
        $this->admin($request);
        $code = DB::transaction(fn () => $access->activation(SecurityDevice::lockForUpdate()->findOrFail($device->id)));

        return back()->with('activation_code', $code)->with('success', 'Previous device access revoked. Activate the replacement browser within 15 minutes.');
    }

    public function revoke(Request $request, SecurityDevice $device)
    {
        $this->admin($request);
        $device->update(['is_active' => false, 'token_hash' => null, 'activation_hash' => null, 'token_expires_at' => null, 'activation_expires_at' => null]);

        return back()->with('success', 'Device access revoked.');
    }

    public function officer(Request $request)
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:150']);
        $pin = (string) random_int(100000, 999999);
        DB::transaction(function () use ($data, $pin) {
            $u = User::create(['name' => $data['name'], 'email' => 'security-'.Str::uuid().'@security.local', 'password' => Str::random(64), 'role' => 'SECURITY', 'is_active' => true, 'access_status' => 'approved', 'local_login_enabled' => false]);
            SecurityOfficer::create(['user_id' => $u->id, 'pin_hash' => Hash::make($pin), 'pin_changed_at' => now()]);
        });

        return back()->with('officer_pin', $pin)->with('success', 'Security officer created. Share this PIN only with the named officer. It is displayed once.');
    }

    public function updateOfficer(Request $request, SecurityOfficer $officer)
    {
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:150', 'is_active' => 'required|boolean']);
        DB::transaction(function () use ($officer, $data) {
            $o = SecurityOfficer::lockForUpdate()->findOrFail($officer->id);
            $o->user()->update(['name' => $data['name']]);
            $o->update(['is_active' => (bool) $data['is_active']]);
        });

        return back()->with('success', 'Security officer updated.');
    }

    public function resetPin(Request $request, SecurityOfficer $officer)
    {
        $this->admin($request);
        $pin = DB::transaction(function () use ($officer) {
            $locked = SecurityOfficer::lockForUpdate()->findOrFail($officer->id);
            do {
                $pin = (string) random_int(100000, 999999);
            } while (Hash::check($pin, $locked->pin_hash));
            $locked->update(['pin_hash' => Hash::make($pin), 'pin_changed_at' => now()]);

            return $pin;
        });
        RateLimiter::clear('security-pin:'.$officer->id);

        return back()->with('officer_pin', $pin)->with('success', 'PIN reset for '.$officer->user->name.'. The previous PIN no longer works. The new PIN is displayed once.');
    }
}
