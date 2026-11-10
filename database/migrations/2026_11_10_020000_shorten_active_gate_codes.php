<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceUnusedCodes('ppc_code');
        $this->replaceUnusedCodes('security_code');
    }

    private function replaceUnusedCodes(string $prefix): void
    {
        $hashColumn = $prefix.'_hash';
        $encryptedColumn = $prefix.'_encrypted';
        $issuedColumn = $prefix.'_issued_at';
        $usedColumn = $prefix.'_used_at';

        DB::table('delivery_documents')->whereNotNull($hashColumn)->whereNull($usedColumn)->orderBy('id')->each(function ($document) use ($hashColumn, $encryptedColumn, $issuedColumn, $usedColumn) {
            do {
                $raw = strtoupper(Str::random(6));
                $hash = hash_hmac('sha256', $raw, (string) config('app.key'));
            } while (DB::table('delivery_documents')->where($hashColumn, $hash)->exists());

            DB::table('delivery_documents')->where('id', $document->id)->update([
                $hashColumn => $hash,
                $encryptedColumn => Crypt::encryptString($raw),
                $issuedColumn => now(),
                $usedColumn => null,
            ]);
        });
    }

    public function down(): void
    {
        // Short active codes stay valid if this data migration is rolled back.
    }
};
