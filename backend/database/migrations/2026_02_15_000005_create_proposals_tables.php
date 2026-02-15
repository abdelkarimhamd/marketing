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
        Schema::create('proposal_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('service', 120)->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('subject', 255)->nullable();
            $table->longText('body_html');
            $table->longText('body_text')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'service', 'is_active'], 'proposal_templates_service_idx');
        });

        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_template_id')->nullable()->constrained('proposal_templates')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pdf_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->unsignedInteger('version_no')->default(1);
            $table->string('status', 40)->default('draft');
            $table->string('service', 120)->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('quote_amount', 15, 2)->nullable();
            $table->string('title', 255)->nullable();
            $table->longText('subject')->nullable();
            $table->longText('body_html')->nullable();
            $table->longText('body_text')->nullable();
            $table->string('share_token', 120)->nullable();
            $table->string('public_url', 2000)->nullable();
            $table->string('accepted_by', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'share_token']);
            $table->unique(['tenant_id', 'lead_id', 'proposal_template_id', 'version_no'], 'proposals_version_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'service']);
            $table->index(['tenant_id', 'sent_at']);
            $table->index(['tenant_id', 'opened_at']);
            $table->index(['tenant_id', 'accepted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposals');
        Schema::dropIfExists('proposal_templates');
    }
};
