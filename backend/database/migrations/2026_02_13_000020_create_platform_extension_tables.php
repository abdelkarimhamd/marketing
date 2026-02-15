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
        Schema::create('archived_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_message_id')->nullable();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->json('payload');
            $table->timestamp('archived_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'archived_at']);
        });

        Schema::create('archived_webhooks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('source_webhook_id')->nullable();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 120)->nullable();
            $table->json('payload');
            $table->timestamp('archived_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'archived_at']);
        });

        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('name');
            $table->json('config')->nullable();
            $table->text('secrets')->nullable();
            $table->json('capabilities')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'provider']);
        });

        Schema::create('tenant_sso_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('oidc');
            $table->text('settings')->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('enforce_sso')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'provider']);
        });

        Schema::create('scim_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });

        Schema::create('realtime_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_name');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'id']);
            $table->index(['event_name', 'occurred_at']);
        });

        Schema::create('call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 64)->nullable();
            $table->string('provider_call_id')->nullable();
            $table->string('direction', 24)->default('outbound');
            $table->string('status', 24)->default('queued');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->string('outcome', 64)->nullable();
            $table->text('notes')->nullable();
            $table->string('recording_url')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('next_follow_up_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_sandboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sandbox_tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->string('name');
            $table->string('status', 32)->default('ready');
            $table->boolean('anonymized')->default(true);
            $table->timestamp('last_cloned_at')->nullable();
            $table->timestamp('last_promoted_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('custom_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('entity', 32)->default('lead');
            $table->string('name');
            $table->string('slug');
            $table->string('field_type', 32)->default('text');
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('options')->nullable();
            $table->json('validation_rules')->nullable();
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entity', 'slug']);
            $table->index(['tenant_id', 'entity', 'is_active']);
        });

        Schema::create('lead_custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('custom_field_id')->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'lead_id', 'custom_field_id'], 'lead_custom_field_unique');
            $table->index(['tenant_id', 'custom_field_id']);
        });

        Schema::create('lead_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->text('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('lead_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_form_id')->constrained('lead_forms')->cascadeOnDelete();
            $table->foreignId('custom_field_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->string('source_key');
            $table->string('map_to')->default('meta');
            $table->unsignedInteger('sort_order')->default(1);
            $table->boolean('is_required')->default(false);
            $table->json('validation_rules')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_form_id', 'sort_order']);
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->unsignedInteger('version_no');
            $table->string('status', 32)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'subject_type', 'subject_id', 'version_no'], 'workflow_version_unique');
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24)->default('pending');
            $table->text('comment')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('checkout_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('billing_plan_id')->nullable()->constrained('billing_plans')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('provider', 32)->default('stripe');
            $table->string('provider_session_id')->nullable();
            $table->string('status', 24)->default('pending');
            $table->string('coupon_code')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'provider_session_id']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_sessions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('lead_form_fields');
        Schema::dropIfExists('lead_forms');
        Schema::dropIfExists('lead_custom_field_values');
        Schema::dropIfExists('custom_fields');
        Schema::dropIfExists('tenant_sandboxes');
        Schema::dropIfExists('call_logs');
        Schema::dropIfExists('realtime_events');
        Schema::dropIfExists('scim_access_tokens');
        Schema::dropIfExists('tenant_sso_configs');
        Schema::dropIfExists('integration_connections');
        Schema::dropIfExists('archived_webhooks');
        Schema::dropIfExists('archived_messages');
    }
};
