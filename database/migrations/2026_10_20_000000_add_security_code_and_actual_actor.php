<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_documents', function (Blueprint $table) {
            $table->char('security_code_hash', 64)->nullable()->unique()->after('receiver_name');
            $table->text('security_code_encrypted')->nullable()->after('security_code_hash');
            $table->timestamp('security_code_issued_at')->nullable()->after('security_code_encrypted');
            $table->timestamp('security_code_used_at')->nullable()->after('security_code_issued_at');
        });
        Schema::table('document_events', function (Blueprint $table) {
            $table->string('actual_actor_name', 150)->nullable()->after('actor_role');
        });
    }

    public function down(): void
    {
        Schema::table('document_events', fn (Blueprint $table) => $table->dropColumn('actual_actor_name'));
        Schema::table('delivery_documents', function (Blueprint $table) {
            $table->dropUnique(['security_code_hash']);
            $table->dropColumn(['security_code_hash', 'security_code_encrypted', 'security_code_issued_at', 'security_code_used_at']);
        });
    }
};
