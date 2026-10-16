<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100);
            $table->json('permissions');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('approval_flows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('type', 20)->default('SCRAP');
            $table->string('start_code', 50)->nullable();
            $table->json('draft_notice_roles')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->unsignedInteger('version')->default(0);
            $table->json('published_definition')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('approval_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_flow_id')->constrained();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->unsignedInteger('position')->default(1);
            $table->string('actor_mode', 20)->default('role');
            $table->string('role_code', 50)->nullable();
            $table->json('user_ids')->nullable();
            $table->string('action', 20)->default('approve');
            $table->string('action_label', 100)->default('Approve');
            $table->string('next_code', 50);
            $table->string('delegate_to', 50)->nullable();
            $table->json('notice_roles')->nullable();
            $table->json('delegate_notice_roles')->nullable();
            $table->boolean('allow_return')->default(true);
            $table->timestamps();
            $table->unique(['approval_flow_id', 'code']);
        });
        Schema::table('delivery_documents', function (Blueprint $table) {
            $table->json('approval_snapshot')->nullable();
            $table->string('pending_role_code', 50)->nullable()->index();
            $table->json('pending_user_ids')->nullable();
            $table->boolean('pending_owner_only')->default(false)->index();
        });
        Schema::table('document_events', function (Blueprint $table) {
            $table->string('action_label')->nullable();
            $table->string('status_label')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('sso_tenant_id')->nullable();
            $table->uuid('sso_object_id')->nullable();
            $table->boolean('local_login_enabled')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->unique(['sso_tenant_id', 'sso_object_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['sso_tenant_id', 'sso_object_id']);
            $table->dropColumn(['sso_tenant_id', 'sso_object_id', 'local_login_enabled', 'last_login_at']);
        });
        Schema::table('document_events', fn (Blueprint $table) => $table->dropColumn(['action_label', 'status_label']));
        Schema::table('delivery_documents', fn (Blueprint $table) => $table->dropColumn(['approval_snapshot', 'pending_role_code', 'pending_user_ids', 'pending_owner_only']));
        Schema::dropIfExists('approval_steps');
        Schema::dropIfExists('approval_flows');
        Schema::dropIfExists('roles');
    }
};
