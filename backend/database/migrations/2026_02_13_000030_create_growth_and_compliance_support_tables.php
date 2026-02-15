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
        Schema::create('lead_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('locale', 12)->nullable();
            $table->json('channels')->nullable();
            $table->json('topics')->nullable();
            $table->string('token', 120)->unique();
            $table->timestamp('last_confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'email']);
            $table->index(['tenant_id', 'phone']);
        });

        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->text('prompt')->nullable();
            $table->longText('response')->nullable();
            $table->string('model', 120)->nullable();
            $table->decimal('confidence', 6, 4)->nullable();
            $table->string('sentiment', 24)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('integration_event_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('endpoint_url');
            $table->text('secret')->nullable();
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->text('settings')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 64);
            $table->string('destination', 32)->default('download');
            $table->string('status', 24)->default('pending');
            $table->string('schedule_cron')->nullable();
            $table->json('payload')->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['status', 'next_run_at']);
        });

        Schema::create('country_compliance_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('country_code', 8);
            $table->string('channel', 24);
            $table->string('sender_id')->nullable();
            $table->json('opt_out_keywords')->nullable();
            $table->json('template_constraints')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'country_code', 'channel'], 'country_compliance_unique');
            $table->index(['tenant_id', 'channel', 'is_active']);
        });

        Schema::create('tenant_health_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('health_score', 6, 2)->default(0);
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'snapshot_date'], 'tenant_health_daily_unique');
            $table->index(['snapshot_date', 'health_score']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_health_snapshots');
        Schema::dropIfExists('country_compliance_rules');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('integration_event_subscriptions');
        Schema::dropIfExists('ai_interactions');
        Schema::dropIfExists('lead_preferences');
    }
};
