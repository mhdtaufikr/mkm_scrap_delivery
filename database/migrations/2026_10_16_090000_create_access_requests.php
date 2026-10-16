<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('access_status', 20)->default('approved')->index());
        Schema::create('access_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->string('requested_role');
            $t->string('responsibility', 255);
            $t->text('reason');
            $t->json('profile');
            $t->string('status', 20)->default('pending')->index();
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_requests');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('access_status'));
    }
};
