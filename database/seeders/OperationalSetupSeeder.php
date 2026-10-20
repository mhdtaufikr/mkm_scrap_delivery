<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class OperationalSetupSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([RoleSeeder::class, OperationalAccountSeeder::class, OperationalWorkflowSeeder::class]);
    }
}
