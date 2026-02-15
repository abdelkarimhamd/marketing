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
        Schema::create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('host');
            $table->string('kind', 32)->default('landing');
            $table->boolean('is_primary')->default(false);
            $table->string('cname_target')->nullable();
            $table->string('verification_token')->nullable();
            $table->string('verification_status', 32)->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_error')->nullable();
            $table->string('ssl_status', 32)->default('pending');
            $table->string('ssl_provider', 120)->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('ssl_last_checked_at')->nullable();
            $table->text('ssl_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('host');
            $table->unique('verification_token');
            $table->index(['tenant_id', 'kind']);
            $table->index(['verification_status', 'ssl_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_domains');
    }
};

