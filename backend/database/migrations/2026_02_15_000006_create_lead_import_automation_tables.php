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
        Schema::create('lead_import_presets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('slug', 180);
            $table->text('description')->nullable();
            $table->json('mapping')->nullable();
            $table->json('defaults')->nullable();
            $table->string('dedupe_policy', 24)->default('skip');
            $table->json('dedupe_keys')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug'], 'lead_import_presets_tenant_slug_unique');
            $table->index(['tenant_id', 'is_active'], 'lead_import_presets_tenant_active_idx');
        });

        Schema::create('lead_import_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('preset_id')->nullable()->constrained('lead_import_presets')->nullOnDelete();
            $table->string('name', 150);
            $table->string('source_type', 24)->default('url');
            $table->text('source_config')->nullable();
            $table->json('mapping')->nullable();
            $table->json('defaults')->nullable();
            $table->string('dedupe_policy', 24)->default('skip');
            $table->json('dedupe_keys')->nullable();
            $table->boolean('auto_assign')->default(true);
            $table->string('schedule_cron', 120);
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('last_processed_count')->default(0);
            $table->string('last_status', 24)->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'next_run_at'], 'lead_import_schedules_tenant_due_idx');
            $table->index(['source_type', 'is_active'], 'lead_import_schedules_source_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_import_schedules');
        Schema::dropIfExists('lead_import_presets');
    }
};

