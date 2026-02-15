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
        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'provider_cost')) {
                $table->decimal('provider_cost', 12, 4)->nullable()->after('cost_estimate');
            }

            if (! Schema::hasColumn('messages', 'overhead_cost')) {
                $table->decimal('overhead_cost', 12, 4)->nullable()->after('provider_cost');
            }

            if (! Schema::hasColumn('messages', 'revenue_amount')) {
                $table->decimal('revenue_amount', 12, 4)->nullable()->after('overhead_cost');
            }

            if (! Schema::hasColumn('messages', 'profit_amount')) {
                $table->decimal('profit_amount', 12, 4)->nullable()->after('revenue_amount');
            }

            if (! Schema::hasColumn('messages', 'margin_percent')) {
                $table->decimal('margin_percent', 7, 4)->nullable()->after('profit_amount');
            }

            if (! Schema::hasColumn('messages', 'cost_tracked_at')) {
                $table->timestamp('cost_tracked_at')->nullable()->after('margin_percent');
            }

            if (! Schema::hasColumn('messages', 'cost_currency')) {
                $table->string('cost_currency', 8)->nullable()->after('cost_tracked_at');
            }

            $table->index(['tenant_id', 'channel', 'cost_tracked_at'], 'messages_cost_tracking_idx');
            $table->index(['tenant_id', 'campaign_id', 'cost_tracked_at'], 'messages_campaign_cost_idx');
        });

        Schema::table('billing_usage_records', function (Blueprint $table): void {
            if (! Schema::hasColumn('billing_usage_records', 'provider_cost_total')) {
                $table->decimal('provider_cost_total', 12, 4)->default(0)->after('cost_total');
            }

            if (! Schema::hasColumn('billing_usage_records', 'overhead_cost_total')) {
                $table->decimal('overhead_cost_total', 12, 4)->default(0)->after('provider_cost_total');
            }

            if (! Schema::hasColumn('billing_usage_records', 'revenue_total')) {
                $table->decimal('revenue_total', 12, 4)->default(0)->after('overhead_cost_total');
            }

            if (! Schema::hasColumn('billing_usage_records', 'profit_total')) {
                $table->decimal('profit_total', 12, 4)->default(0)->after('revenue_total');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('billing_usage_records', function (Blueprint $table): void {
            if (Schema::hasColumn('billing_usage_records', 'profit_total')) {
                $table->dropColumn('profit_total');
            }

            if (Schema::hasColumn('billing_usage_records', 'revenue_total')) {
                $table->dropColumn('revenue_total');
            }

            if (Schema::hasColumn('billing_usage_records', 'overhead_cost_total')) {
                $table->dropColumn('overhead_cost_total');
            }

            if (Schema::hasColumn('billing_usage_records', 'provider_cost_total')) {
                $table->dropColumn('provider_cost_total');
            }
        });

        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'cost_currency')) {
                $table->dropColumn('cost_currency');
            }

            if (Schema::hasColumn('messages', 'cost_tracked_at')) {
                $table->dropColumn('cost_tracked_at');
            }

            if (Schema::hasColumn('messages', 'margin_percent')) {
                $table->dropColumn('margin_percent');
            }

            if (Schema::hasColumn('messages', 'profit_amount')) {
                $table->dropColumn('profit_amount');
            }

            if (Schema::hasColumn('messages', 'revenue_amount')) {
                $table->dropColumn('revenue_amount');
            }

            if (Schema::hasColumn('messages', 'overhead_cost')) {
                $table->dropColumn('overhead_cost');
            }

            if (Schema::hasColumn('messages', 'provider_cost')) {
                $table->dropColumn('provider_cost');
            }

            $table->dropIndex('messages_cost_tracking_idx');
            $table->dropIndex('messages_campaign_cost_idx');
        });
    }
};

