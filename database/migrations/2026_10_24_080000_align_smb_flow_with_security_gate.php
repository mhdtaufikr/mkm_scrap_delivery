<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $flow = DB::table('approval_flows')->where('name', 'Operational Scrap Release - October 2026')->first();
        if (! $flow) {
            return;
        }

        DB::transaction(function () use ($flow) {
            DB::table('approval_steps')->where('approval_flow_id', $flow->id)->where('code', 'HRA_APPROVAL')->update([
                'name' => 'Persetujuan HRA', 'next_code' => 'DRIVER_HANDOVER',
                'action_label' => 'Setujui HRA', 'notice_roles' => json_encode(['HRA_SH', 'SECURITY']), 'updated_at' => now(),
            ]);
            DB::table('approval_steps')->where('approval_flow_id', $flow->id)->where('code', 'DRIVER_HANDOVER')->update([
                'name' => 'Tanda Tangan Pengemudi & Pemeriksaan Security', 'actor_mode' => 'role',
                'role_code' => 'SECURITY', 'user_ids' => json_encode([]), 'action' => 'release',
                'action_label' => 'Selesaikan pemeriksaan dan beri stempel digital', 'next_code' => 'COMPLETED',
                'allow_return' => false, 'updated_at' => now(),
            ]);
            DB::table('approval_steps')->where('approval_flow_id', $flow->id)->whereIn('code', ['WAREHOUSE_PICKUP', 'SECURITY'])->delete();

            $definition = json_decode($flow->published_definition ?: 'null', true);
            if ($definition) {
                $definition['steps']['HRA_APPROVAL'] = array_merge($definition['steps']['HRA_APPROVAL'], [
                    'name' => 'Persetujuan HRA', 'next_code' => 'DRIVER_HANDOVER',
                    'action_label' => 'Setujui HRA', 'notice_roles' => ['HRA_SH', 'SECURITY'],
                ]);
                $definition['steps']['DRIVER_HANDOVER'] = array_merge($definition['steps']['DRIVER_HANDOVER'], [
                    'name' => 'Tanda Tangan Pengemudi & Pemeriksaan Security', 'actor_mode' => 'role',
                    'role_code' => 'SECURITY', 'user_ids' => [], 'action' => 'release',
                    'action_label' => 'Selesaikan pemeriksaan dan beri stempel digital',
                    'next_code' => 'COMPLETED', 'allow_return' => false,
                ]);
                unset($definition['steps']['WAREHOUSE_PICKUP'], $definition['steps']['SECURITY']);
                DB::table('approval_flows')->where('id', $flow->id)->update([
                    'published_definition' => json_encode($definition), 'updated_at' => now(),
                ]);
            }

            DB::table('delivery_documents')->where('type', 'SCRAP')->whereIn('status', [
                'SALES_APPROVAL', 'HRA_APPROVAL', 'WAREHOUSE_PICKUP', 'DRIVER_HANDOVER', 'SECURITY',
            ])->orderBy('id')->get()->each(function ($document) use ($flow, $definition) {
                $snapshot = json_decode($document->approval_snapshot ?: 'null', true);
                if (($snapshot['flow_id'] ?? null) !== $flow->id || ! $definition) {
                    return;
                }
                $snapshot['steps'] = $definition['steps'];
                $status = in_array($document->status, ['WAREHOUSE_PICKUP', 'SECURITY'], true)
                    ? 'DRIVER_HANDOVER' : $document->status;
                $updates = ['status' => $status, 'approval_snapshot' => json_encode($snapshot), 'updated_at' => now()];

                if (! $document->security_code_hash) {
                    do {
                        $raw = strtoupper(Str::random(12));
                        $hash = hash_hmac('sha256', $raw, (string) config('app.key'));
                    } while (DB::table('delivery_documents')->where('security_code_hash', $hash)->exists());
                    $updates += [
                        'security_code_hash' => $hash,
                        'security_code_encrypted' => Crypt::encryptString(implode('-', str_split($raw, 4))),
                        'security_code_issued_at' => now(), 'security_code_used_at' => null,
                    ];
                }
                if ($status === 'DRIVER_HANDOVER') {
                    $updates += ['pending_owner_only' => false, 'pending_role_code' => 'SECURITY', 'pending_user_ids' => json_encode([])];
                    if (! $document->driver_signed_at && ! $document->driver_token_hash) {
                        $token = Str::random(64);
                        $updates += [
                            'driver_token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
                            'driver_token_encrypted' => Crypt::encryptString($token),
                        ];
                    }
                }
                DB::table('delivery_documents')->where('id', $document->id)->update($updates);
            });
        });
    }

    public function down(): void
    {
        // Workflow changes are intentionally not reversed because completed audit trails must remain valid.
    }
};
