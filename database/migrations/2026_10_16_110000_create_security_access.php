<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_devices', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->boolean('is_active')->default(true);
            $t->string('activation_hash', 64)->nullable()->unique();
            $t->timestamp('activation_expires_at')->nullable();
            $t->string('token_hash', 64)->nullable();
            $t->timestamp('token_expires_at')->nullable();
            $t->timestamp('paired_at')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
        });
        Schema::create('security_officers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained()->restrictOnDelete();
            $t->string('pin_hash');
            $t->boolean('is_active')->default(true);
            $t->timestamp('pin_changed_at')->nullable();
            $t->timestamps();
        });
        Schema::table('document_events', function (Blueprint $t) {
            $t->foreignId('security_device_id')->nullable()->constrained('security_devices')->restrictOnDelete();
            $t->string('security_device_name', 100)->nullable();
            $t->string('security_officer_name', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_events', function (Blueprint $t) {
            $t->dropConstrainedForeignId('security_device_id');
            $t->dropColumn(['security_device_name', 'security_officer_name']);
        });
        Schema::dropIfExists('security_officers');
        Schema::dropIfExists('security_devices');
    }
};
