<?php

namespace Database\Seeders;

use App\Models\ApprovalFlow;
use App\Services\ApprovalConfiguration;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ApprovalFlowSeeder extends Seeder
{
    public function run(): void
    {
        if (ApprovalFlow::where('type', 'SCRAP')->exists()) {
            return;
        }
        DB::transaction(function () {
            $flow = ApprovalFlow::create(['name' => 'Monthly Scrap — MOM 15 Oktober 2026', 'type' => 'SCRAP', 'start_code' => 'PROC_STAFF', 'draft_notice_roles' => ['SECURITY', 'PROC_STAFF']]);
            $steps = [
                ['code' => 'PROC_STAFF', 'name' => 'Procurement Staff Review', 'role_code' => 'PROC_STAFF', 'action' => 'next', 'action_label' => 'Next approval → Procurement DH', 'next_code' => 'PROC_DH', 'delegate_to' => 'HRD', 'delegate_notice_roles' => ['PROC_DH', 'PROC_SH'], 'notice_roles' => ['SECURITY']],
                ['code' => 'PROC_DH', 'name' => 'Procurement DH Approval', 'role_code' => 'PROC_DH', 'next_code' => 'HRD', 'notice_roles' => ['PROC_SH']],
                ['code' => 'HRD', 'name' => 'HRD Approval', 'role_code' => 'HRD', 'next_code' => 'HANDOVER', 'notice_roles' => ['PROC_SH']],
                ['code' => 'HANDOVER', 'name' => 'Signed Handover', 'actor_mode' => 'owner', 'action' => 'handover', 'action_label' => 'Save evidence & send to Security', 'next_code' => 'SECURITY', 'allow_return' => false],
                ['code' => 'SECURITY', 'name' => 'Security Inspection', 'role_code' => 'SECURITY', 'action' => 'release', 'action_label' => 'Approve & record goods release', 'next_code' => 'COMPLETED'],
            ];
            foreach ($steps as $i => $step) {
                $flow->steps()->create(['position' => $i + 1, 'actor_mode' => 'role', 'action' => 'approve', 'action_label' => 'Approve', ...$step]);
            }
            app(ApprovalConfiguration::class)->publish($flow, null);
        });
    }
}
