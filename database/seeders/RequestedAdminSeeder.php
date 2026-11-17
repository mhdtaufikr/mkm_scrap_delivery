<?php

namespace Database\Seeders;

use App\Models\AccessRequest;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RequestedAdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower((string) config('services.azure.bootstrap_admin_email'));
        $tenant = strtolower((string) config('services.azure.tenant'));
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! Str::isUuid($tenant)) {
            return;
        }
        DB::transaction(function () use ($email, $tenant) {
            $user = User::firstOrCreate(['email' => $email], ['name' => 'MKM02120', 'username' => null, 'password' => Str::password(32), 'role' => 'ADMIN', 'is_active' => true, 'must_change_password' => false]);
            // Idempotent enrollment: never remove a linked Object ID or replace an existing password.
            $user->role = 'ADMIN';
            $user->access_status = 'approved';
            $user->is_active = true;
            if (! $user->sso_object_id) {
                $user->sso_tenant_id = $tenant;
                $user->sso_link_pending = true;
            }
            $user->save();

            AccessRequest::where('user_id', $user->id)
                ->where('requested_role', 'ADMIN')
                ->where('status', 'pending')
                ->update([
                    'status' => 'approved',
                    'reviewed_at' => now(),
                    'review_note' => 'Approved by the configured bootstrap administrator seeder.',
                ]);
        });
    }
}
