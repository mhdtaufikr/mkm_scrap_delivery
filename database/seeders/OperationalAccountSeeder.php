<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OperationalAccountSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['M. Najmal', 'mkm01868', 'mkm01868@ptmkm.co.id', 'PPC', true],
            ['Mujib', 'mkm01576', 'mkm01576@ptmkm.co.id', 'PPC', true],
            ['Aji Bayu', 'mkm02099', 'mkm02099@ptmkm.co.id', 'PPC_SH', true],
            ['Faqih M', 'mkm01727', 'mkm01727@ptmkm.co.id', 'PPC_DH', true],
            ['Yasin', 'mkm01478', 'mkm01478@ptmkm.co.id', 'WAREHOUSE_PICKUP', true],
            ['Fisty Savira', 'mkm02119', 'mkm02119@ptmkm.co.id', 'SALES', true],
            ['Masrukhan', 'mkm01185', 'mkm01185@ptmkm.co.id', 'SALES_DH', true],
            ['Aji W', 'mkm02088', 'mkm02088@ptmkm.co.id', 'SALES_SH', true],
            ['Rahmat W', 'mkm001531', 'mkm001531@ptmkm.co.id', 'HRA_DH', true],
            ['Agus F', 'mkm01436', 'mkm01436@ptmkm.co.id', 'HRA_SH', true],
            ['DigiMAMS', null, 'digimams@ptmkm.co.id', 'PPC', false],
        ];
        $password = config('workflow.initial_password');
        $local = is_string($password) && strlen($password) >= 10;
        $tenant = strtolower((string) config('services.azure.tenant'));
        $ssoConfigured = Str::isUuid($tenant);

        foreach ($accounts as [$name, $username, $email, $role, $interactive]) {
            $user = User::firstOrNew(['email' => $email]);
            $new = ! $user->exists;
            $user->fill([
                'name' => $name, 'username' => $username, 'role' => $role,
                'access_status' => 'approved', 'is_active' => true, 'must_change_password' => false,
            ]);
            if ($new) {
                $user->fill([
                    'password' => $local ? $password : Str::random(64),
                    'local_login_enabled' => $interactive && $local,
                    'must_change_password' => false,
                ]);
            }
            if ($interactive && ! $local && $ssoConfigured && ! $user->sso_object_id && ! $user->sso_tenant_id) {
                $user->fill([
                    'sso_tenant_id' => $tenant,
                    'sso_link_pending' => true,
                ]);
            }
            $user->save();
            $this->command?->line(($username ?: $email).' · '.$role.' · '.($new ? 'created' : 'updated').($new && ! ($interactive && $local) ? ' · SSO/service-only' : ''));
        }

        User::firstOrCreate(['username' => 'security.gate'], [
            'name' => 'Security Gate System', 'email' => 'security-gate@system.local',
            'role' => 'SECURITY', 'password' => Str::random(64), 'access_status' => 'approved',
            'is_active' => true, 'local_login_enabled' => false, 'must_change_password' => false,
        ]);
    }
}
