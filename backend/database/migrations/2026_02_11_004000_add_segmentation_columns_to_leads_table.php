<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('city')->nullable()->after('company');
            $table->string('interest')->nullable()->after('city');
            $table->string('service')->nullable()->after('interest');
            $table->index(['tenant_id', 'city']);
            $table->index(['tenant_id', 'interest']);
            $table->index(['tenant_id', 'service']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'city']);
            $table->dropIndex(['tenant_id', 'interest']);
            $table->dropIndex(['tenant_id', 'service']);
            $table->dropColumn(['city', 'interest', 'service']);
        });
    }
};
