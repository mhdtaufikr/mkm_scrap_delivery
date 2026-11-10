<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('delivery_documents')->where('type', 'DISPOSAL')->where('status', 'DRAFT')->whereNull('ppc_code_hash')->orderBy('id')->each(function ($document) {
            do {
                $raw = strtoupper(Str::random(6));
                $hash = hash_hmac('sha256', $raw, (string) config('app.key'));
            } while (DB::table('delivery_documents')->where('ppc_code_hash', $hash)->exists());

            DB::table('delivery_documents')->where('id', $document->id)->update([
                'ppc_code_hash' => $hash,
                'ppc_code_encrypted' => Crypt::encryptString($raw),
                'ppc_code_issued_at' => now(),
                'ppc_code_used_at' => null,
            ]);
        });
    }

    public function down(): void
    {
        // Backfilled codes remain valid if this data migration is rolled back.
    }
};
