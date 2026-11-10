<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_documents', function (Blueprint $table) {
            $table->char('ppc_code_hash', 64)->nullable()->unique()->after('security_code_used_at');
            $table->text('ppc_code_encrypted')->nullable()->after('ppc_code_hash');
            $table->timestamp('ppc_code_issued_at')->nullable()->after('ppc_code_encrypted');
            $table->timestamp('ppc_code_used_at')->nullable()->after('ppc_code_issued_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_documents', function (Blueprint $table) {
            $table->dropUnique(['ppc_code_hash']);
            $table->dropColumn(['ppc_code_hash', 'ppc_code_encrypted', 'ppc_code_issued_at', 'ppc_code_used_at']);
        });
    }
};
