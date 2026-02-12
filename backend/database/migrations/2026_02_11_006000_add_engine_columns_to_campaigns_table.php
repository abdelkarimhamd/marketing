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
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('campaign_type')->default('broadcast')->after('channel');
            $table->timestamp('launched_at')->nullable()->after('end_at');
            $table->index(['tenant_id', 'campaign_type', 'status'], 'campaigns_type_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_type_status_idx');
            $table->dropColumn(['campaign_type', 'launched_at']);
        });
    }
};
