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
        Schema::create('consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 24);
            $table->boolean('granted');
            $table->string('source', 64)->default('system');
            $table->string('proof_method', 120)->nullable();
            $table->string('proof_ref', 255)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('collected_at')->useCurrent();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id', 'channel']);
            $table->index(['tenant_id', 'collected_at']);
        });

        Schema::create('billing_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('seat_limit')->default(1);
            $table->unsignedInteger('message_bundle')->default(0);
            $table->decimal('monthly_price', 10, 2)->default(0);
            $table->decimal('overage_price_per_message', 10, 4)->default(0);
            $table->boolean('hard_limit')->default(false);
            $table->json('addons')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('seat_limit_override')->nullable();
            $table->unsignedInteger('message_bundle_override')->nullable();
            $table->decimal('overage_price_override', 10, 4)->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('provider_subscription_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['current_period_start', 'current_period_end'], 'tenant_subs_period_idx');
        });

        Schema::create('billing_usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 24);
            $table->date('period_date');
            $table->unsignedInteger('messages_count')->default(0);
            $table->decimal('cost_total', 12, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel', 'period_date'], 'usage_daily_unique');
            $table->index(['tenant_id', 'period_date']);
        });

        Schema::create('billing_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('status', 32)->default('draft');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('overage_total', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->string('currency', 8)->default('USD');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['period_start', 'period_end']);
        });

        Schema::create('billing_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('billing_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32)->default('line');
            $table->string('description');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('total_price', 12, 2)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_invoice_items');
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('billing_usage_records');
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('billing_plans');
        Schema::dropIfExists('consent_events');
    }
};
