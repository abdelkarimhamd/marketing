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
        Schema::table('tenants', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenants', 'public_key')) {
                $table->string('public_key', 80)->nullable()->unique()->after('slug');
            }
        });

        $createTable = static function (string $tableName, $callback): void {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, $callback);
            }
        };

        $createTable('accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('industry')->nullable();
            $table->string('size')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->text('notes')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'domain']);
            $table->index(['tenant_id', 'owner_user_id']);
        });

        $createTable('account_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('job_title')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'account_id', 'lead_id'], 'account_contacts_unique');
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'account_id', 'is_primary']);
        });

        $createTable('tracking_visitors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->string('session_id', 64)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_hash', 128)->nullable();
            $table->string('phone_hash', 128)->nullable();
            $table->string('first_url', 2000)->nullable();
            $table->string('last_url', 2000)->nullable();
            $table->string('referrer', 2000)->nullable();
            $table->json('utm_json')->nullable();
            $table->json('traits_json')->nullable();
            $table->string('first_ip', 64)->nullable();
            $table->string('last_ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'visitor_id'], 'tracking_visitors_unique');
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'email_hash']);
            $table->index(['tenant_id', 'phone_hash']);
            $table->index(['tenant_id', 'last_seen_at']);
        });

        $createTable('tracking_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64);
            $table->string('session_id', 64)->nullable();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 80);
            $table->string('url', 2000)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('referrer', 2000)->nullable();
            $table->json('utm_json')->nullable();
            $table->json('props_json')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_type', 'occurred_at'], 'tracking_events_type_idx');
            $table->index(['tenant_id', 'visitor_id', 'occurred_at'], 'tracking_events_visitor_idx');
            $table->index(['tenant_id', 'lead_id', 'occurred_at'], 'tracking_events_lead_idx');
            $table->index(['tenant_id', 'path']);
        });

        $createTable('personalization_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('enabled')->default(true);
            $table->json('match_rules_json')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'enabled', 'priority']);
        });

        $createTable('personalization_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personalization_rule_id')->constrained('personalization_rules')->cascadeOnDelete();
            $table->string('variant_key', 80);
            $table->unsignedInteger('weight')->default(100);
            $table->boolean('is_control')->default(false);
            $table->json('changes_json')->nullable();
            $table->timestamps();

            $table->unique(['personalization_rule_id', 'variant_key'], 'personalization_variant_unique');
            $table->index(['tenant_id', 'personalization_rule_id']);
        });

        $createTable('device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('token', 255);
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'token'], 'device_tokens_unique');
            $table->index(['tenant_id', 'user_id', 'platform']);
        });

        $createTable('calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 20)->default('outbound');
            $table->string('status', 40)->default('queued');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('provider_call_id', 160)->nullable();
            $table->string('recording_url', 2000)->nullable();
            $table->string('disposition', 120)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'provider_call_id'], 'calls_provider_unique');
            $table->index(['tenant_id', 'lead_id', 'started_at']);
            $table->index(['tenant_id', 'user_id', 'started_at']);
            $table->index(['tenant_id', 'status']);
        });

        $createTable('portal_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->string('request_type', 40);
            $table->string('status', 40)->default('new');
            $table->json('payload_json')->nullable();
            $table->json('meta')->nullable();
            $table->string('source_ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'request_type', 'status']);
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        $createTable('data_quality_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('run_type', 40)->default('normalize');
            $table->string('status', 40)->default('queued');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('stats_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'run_type', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        $createTable('merge_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('candidate_a_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('candidate_b_id')->constrained('leads')->cascadeOnDelete();
            $table->string('reason', 120);
            $table->decimal('confidence', 5, 2)->default(0);
            $table->string('status', 40)->default('pending');
            $table->json('meta')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'candidate_a_id', 'candidate_b_id', 'reason'],
                'merge_suggestions_unique'
            );
            $table->index(['tenant_id', 'status', 'confidence']);
            $table->index(['tenant_id', 'reviewed_by']);
        });

        $createTable('experiments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('scope', 40)->default('landing');
            $table->string('status', 40)->default('draft');
            $table->decimal('holdout_pct', 6, 2)->default(0);
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->json('config_json')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'scope', 'status']);
            $table->index(['tenant_id', 'start_at', 'end_at']);
        });

        $createTable('experiment_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('experiment_id')->constrained('experiments')->cascadeOnDelete();
            $table->string('key', 80);
            $table->unsignedInteger('weight')->default(100);
            $table->boolean('is_control')->default(false);
            $table->json('config_json')->nullable();
            $table->timestamps();

            $table->unique(['experiment_id', 'key'], 'experiment_variants_unique');
            $table->index(['tenant_id', 'experiment_id']);
        });

        $createTable('experiment_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('experiment_id')->constrained('experiments')->cascadeOnDelete();
            $table->foreignId('experiment_variant_id')->nullable()->constrained('experiment_variants')->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_id', 64)->nullable();
            $table->string('assignment_key', 180);
            $table->string('variant_key', 80)->nullable();
            $table->boolean('is_holdout')->default(false);
            $table->timestamp('assigned_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'experiment_id', 'assignment_key'], 'experiment_assignments_unique');
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'visitor_id']);
        });

        $createTable('experiment_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('experiment_id')->constrained('experiments')->cascadeOnDelete();
            $table->foreignId('experiment_variant_id')->nullable()->constrained('experiment_variants')->nullOnDelete();
            $table->string('metric_key', 80);
            $table->decimal('metric_value', 14, 4)->default(0);
            $table->timestamp('measured_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'experiment_id', 'metric_key'], 'experiment_metrics_idx');
            $table->index(['tenant_id', 'measured_at']);
        });

        $createTable('marketplace_apps', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('manifest_url', 2000)->nullable();
            $table->json('permissions_json')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $createTable('app_installs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_app_id')->constrained('marketplace_apps')->cascadeOnDelete();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('installed');
            $table->json('config_json')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'marketplace_app_id'], 'app_installs_unique');
            $table->index(['tenant_id', 'status']);
        });

        $createTable('app_secrets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_install_id')->constrained('app_installs')->cascadeOnDelete();
            $table->string('key_id', 80);
            $table->text('secret_encrypted');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'app_install_id', 'key_id'], 'app_secrets_unique');
            $table->index(['tenant_id', 'revoked_at']);
        });

        $createTable('app_webhooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_install_id')->constrained('app_installs')->cascadeOnDelete();
            $table->string('endpoint_url', 2000);
            $table->json('events_json')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'app_install_id', 'is_active'], 'app_webhooks_active_idx');
        });

        $createTable('domain_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_name', 120);
            $table->string('subject_type', 255)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'event_name', 'occurred_at'], 'domain_events_name_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'domain_events_subject_idx');
        });

        $createTable('app_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('app_webhook_id')->constrained('app_webhooks')->cascadeOnDelete();
            $table->foreignId('domain_event_id')->constrained('domain_events')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_no')->default(1);
            $table->string('status', 40)->default('queued');
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_headers')->nullable();
            $table->json('request_payload')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'created_at'], 'app_webhook_deliveries_status_idx');
            $table->index(['tenant_id', 'domain_event_id']);
        });

        $createTable('ai_summaries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->longText('summary');
            $table->string('model', 120)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id', 'generated_at'], 'ai_summaries_lead_idx');
        });

        $createTable('ai_recommendations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('type', 80);
            $table->json('payload_json')->nullable();
            $table->decimal('score', 8, 4)->default(0);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id', 'generated_at'], 'ai_recommendations_lead_idx');
            $table->index(['tenant_id', 'type', 'generated_at'], 'ai_recommendations_type_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_recommendations');
        Schema::dropIfExists('ai_summaries');
        Schema::dropIfExists('app_webhook_deliveries');
        Schema::dropIfExists('domain_events');
        Schema::dropIfExists('app_webhooks');
        Schema::dropIfExists('app_secrets');
        Schema::dropIfExists('app_installs');
        Schema::dropIfExists('marketplace_apps');
        Schema::dropIfExists('experiment_metrics');
        Schema::dropIfExists('experiment_assignments');
        Schema::dropIfExists('experiment_variants');
        Schema::dropIfExists('experiments');
        Schema::dropIfExists('merge_suggestions');
        Schema::dropIfExists('data_quality_runs');
        Schema::dropIfExists('portal_requests');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('personalization_variants');
        Schema::dropIfExists('personalization_rules');
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('tracking_visitors');
        Schema::dropIfExists('account_contacts');
        Schema::dropIfExists('accounts');

        Schema::table('tenants', function (Blueprint $table): void {
            if (Schema::hasColumn('tenants', 'public_key')) {
                $table->dropUnique('tenants_public_key_unique');
                $table->dropColumn('public_key');
            }
        });
    }
};
