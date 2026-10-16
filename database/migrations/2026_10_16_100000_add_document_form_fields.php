<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_documents', function (Blueprint $t) {
            $t->json('items')->nullable();
            $t->json('form_details')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_documents', fn (Blueprint $t) => $t->dropColumn(['items', 'form_details']));
    }
};
