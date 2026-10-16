<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (User::ROLES as $code => $name) {
            Role::firstOrCreate(['code' => $code], ['name' => $name, 'is_active' => true, 'permissions' => match ($code) {
                'ADMIN' => ['users.manage', 'roles.manage', 'approvals.manage'],
                'PPC' => ['documents.create'],
                default => [],
            }]);
        }
    }
}
