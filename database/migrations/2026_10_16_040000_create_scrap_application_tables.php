<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique();
            $table->string('role')->default('VIEWER')->index();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
        });
        Schema::create('delivery_documents', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->foreignId('created_by')->constrained('users');
            $table->string('type')->default('SCRAP');
            $table->string('status')->default('DRAFT')->index();
            $table->date('document_date');
            $table->dateTime('departure_at')->nullable();
            $table->string('driver_name');
            $table->string('company');
            $table->string('destination');
            $table->string('vehicle');
            $table->string('plate_number');
            $table->text('goods_description');
            $table->string('workflow_reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('handover_path')->nullable();
            $table->string('receiver_name')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('document_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_document_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('actor_role');
            $table->string('action');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->string('evidence_path')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('document_events');
        Schema::dropIfExists('delivery_documents');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['username', 'role', 'is_active', 'must_change_password']));
    }
};
