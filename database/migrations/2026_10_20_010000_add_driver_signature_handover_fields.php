<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_documents', function (Blueprint $table) {
            $table->string('pickup_actual_name', 150)->nullable()->after('receiver_name');
            $table->string('driver_signed_name', 150)->nullable()->after('pickup_actual_name');
            $table->string('driver_signature_path')->nullable()->after('driver_signed_name');
            $table->timestamp('driver_signed_at')->nullable()->after('driver_signature_path');
            $table->char('driver_token_hash', 64)->nullable()->unique()->after('driver_signed_at');
            $table->text('driver_token_encrypted')->nullable()->after('driver_token_hash');
            $table->string('seal_actual_name', 150)->nullable()->after('driver_token_encrypted');
            $table->timestamp('sealed_at')->nullable()->after('seal_actual_name');
        });

        $yasinId = DB::table('users')->where('username', 'mkm01478')->value('id');
        if ($yasinId) {
            DB::table('approval_steps')->where('code', 'DRIVER_HANDOVER')->update([
                'name' => 'Awaiting Driver Handover & Signature',
                'actor_mode' => 'users',
                'role_code' => null,
                'user_ids' => json_encode([(int) $yasinId]),
                'action' => 'approve',
                'action_label' => 'Verify Driver Signature & Apply MKM Stamp',
                'allow_return' => false,
                'updated_at' => now(),
            ]);

            DB::table('approval_flows')->whereNotNull('published_definition')->orderBy('id')->get()->each(function ($flow) use ($yasinId) {
                $definition = json_decode($flow->published_definition, true);
                if (! isset($definition['steps']['DRIVER_HANDOVER'])) {
                    return;
                }
                $definition['steps']['DRIVER_HANDOVER'] = array_merge($definition['steps']['DRIVER_HANDOVER'], [
                    'name' => 'Awaiting Driver Handover & Signature', 'actor_mode' => 'users', 'role_code' => null,
                    'user_ids' => [(int) $yasinId], 'action' => 'approve',
                    'action_label' => 'Verify Driver Signature & Apply MKM Stamp', 'allow_return' => false,
                ]);
                DB::table('approval_flows')->where('id', $flow->id)->update(['published_definition' => json_encode($definition)]);
            });

            DB::table('delivery_documents')->where('status', 'DRIVER_HANDOVER')->orderBy('id')->get()->each(function ($document) use ($yasinId) {
                $snapshot = json_decode($document->approval_snapshot ?: 'null', true);
                if (isset($snapshot['steps']['DRIVER_HANDOVER'])) {
                    $snapshot['steps']['DRIVER_HANDOVER'] = array_merge($snapshot['steps']['DRIVER_HANDOVER'], [
                        'name' => 'Awaiting Driver Handover & Signature', 'actor_mode' => 'users', 'role_code' => null,
                        'user_ids' => [(int) $yasinId], 'action' => 'approve',
                        'action_label' => 'Verify Driver Signature & Apply MKM Stamp', 'allow_return' => false,
                    ]);
                }
                $token = Str::random(64);
                DB::table('delivery_documents')->where('id', $document->id)->update([
                    'approval_snapshot' => $snapshot ? json_encode($snapshot) : null,
                    'pending_owner_only' => false, 'pending_role_code' => null,
                    'pending_user_ids' => json_encode([(int) $yasinId]),
                    'driver_token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
                    'driver_token_encrypted' => Crypt::encryptString($token),
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('delivery_documents', function (Blueprint $table) {
            $table->dropUnique(['driver_token_hash']);
            $table->dropColumn(['pickup_actual_name', 'driver_signed_name', 'driver_signature_path', 'driver_signed_at', 'driver_token_hash', 'driver_token_encrypted', 'seal_actual_name', 'sealed_at']);
        });
    }
};
