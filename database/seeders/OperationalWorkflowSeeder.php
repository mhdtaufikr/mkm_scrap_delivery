<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Models\User;
use App\Services\ApprovalConfiguration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OperationalWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        if (ApprovalFlow::where('name', 'Operational Scrap Release - October 2026')->exists()) {
            return;
        }

        $users = User::whereIn('username', ['mkm02119', 'mkm001531', 'mkm01436'])->pluck('id', 'username');
        if ($users->count() !== 3) {
            throw new \RuntimeException('Run OperationalAccountSeeder before OperationalWorkflowSeeder.');
        }

        DB::transaction(function () use ($users) {
            $flow = ApprovalFlow::create([
                'name' => 'Operational Scrap Release - October 2026', 'type' => 'SCRAP',
                'start_code' => 'SALES_APPROVAL', 'draft_notice_roles' => ['PPC_SH', 'PPC_DH'],
            ]);
            $steps = [
                ['code' => 'SALES_APPROVAL', 'name' => 'Procurement & Sales Approval', 'actor_mode' => 'users', 'user_ids' => [$users['mkm02119']], 'action' => 'approve', 'action_label' => 'Approve Procurement & Sales', 'next_code' => 'HRA_APPROVAL', 'notice_roles' => ['SALES_SH', 'SALES_DH']],
                ['code' => 'HRA_APPROVAL', 'name' => 'Persetujuan HRA', 'actor_mode' => 'users', 'user_ids' => [$users['mkm001531'], $users['mkm01436']], 'action' => 'approve', 'action_label' => 'Setujui HRA', 'next_code' => 'DRIVER_HANDOVER', 'notice_roles' => ['HRA_SH', 'SECURITY']],
                ['code' => 'DRIVER_HANDOVER', 'name' => 'Tanda Tangan Pengemudi & Pemeriksaan Security', 'actor_mode' => 'role', 'role_code' => 'SECURITY', 'action' => 'release', 'action_label' => 'Selesaikan pemeriksaan dan beri stempel digital', 'next_code' => 'COMPLETED', 'allow_return' => false],
            ];
            foreach ($steps as $position => $step) {
                $flow->steps()->create(['position' => $position + 1, 'role_code' => null, 'user_ids' => null, 'notice_roles' => [], 'delegate_notice_roles' => [], 'allow_return' => true, ...$step]);
            }
            app(ApprovalConfiguration::class)->publish($flow, null);
        });
    }
}
