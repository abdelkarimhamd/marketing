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
        Schema::create('high_risk_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('action', 120);
            $table->string('subject_type', 190)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('fingerprint', 64);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('required_approvals')->default(1);
            $table->unsignedSmallInteger('approved_count')->default(0);
            $table->string('status', 32)->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'action', 'status']);
            $table->index(['tenant_id', 'requested_by', 'status']);
            $table->index(['tenant_id', 'fingerprint', 'status']);
            $table->index(['tenant_id', 'subject_type', 'subject_id']);
        });

        Schema::create('high_risk_approval_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('high_risk_approval_id')->constrained('high_risk_approvals')->cascadeOnDelete();
            $table->unsignedSmallInteger('stage_no')->default(1);
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 24);
            $table->text('comment')->nullable();
            $table->timestamp('reviewed_at')->useCurrent();
            $table->timestamps();

            $table->index(['tenant_id', 'high_risk_approval_id', 'stage_no'], 'hr_approval_stage_idx');
            $table->index(['tenant_id', 'reviewer_id'], 'hr_approval_reviewer_idx');
            $table->unique(
                ['high_risk_approval_id', 'reviewer_id'],
                'hr_approval_unique_reviewer'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('high_risk_approval_reviews');
        Schema::dropIfExists('high_risk_approvals');
    }
};
