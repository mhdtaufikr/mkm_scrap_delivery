<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class LocalAccountSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local') || ! config('demo.enabled')) {
            return;
        }
        $password = config('demo.password');
        if (! is_string($password) || strlen($password) < 10) {
            throw new \RuntimeException('Set LOCAL_DEMO_PASSWORD (minimal 10 karakter) untuk seeder accounts lokal.');
        }
        foreach (['admin.scrap' => 'ADMIN', 'ppc.scrap' => 'PPC', 'proc.staff' => 'PROC_STAFF', 'proc.dh' => 'PROC_DH', 'proc.sh' => 'PROC_SH', 'hrd.scrap' => 'HRD', 'security.scrap' => 'SECURITY', 'viewer.scrap' => 'VIEWER'] as $username => $role) {
            $user = User::firstOrCreate(['username' => $username], ['name' => User::ROLES[$role].' (Local)', 'email' => $username.'@scrap.local', 'role' => $role, 'password' => $password, 'is_active' => true, 'must_change_password' => false, 'local_login_enabled' => true]);
            $user->update(['must_change_password' => false]);
            $this->command?->line($username.' · '.$role.($user->wasRecentlyCreated ? ' · dibuat' : ' · sudah ada, tidak diubah'));
        }
    }
}
