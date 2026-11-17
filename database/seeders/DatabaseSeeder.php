<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $seeders = [RoleSeeder::class, ApprovalFlowSeeder::class];
        if (! app()->environment('testing')) {
            $seeders[] = OperationalSetupSeeder::class;
        }
        $this->call([...$seeders, ExistingDocumentApprovalSeeder::class, LocalAccountSeeder::class]);
    }
}
