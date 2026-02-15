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
        Schema::create('tenant_encryption_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('key_version')->default(1);
            $table->string('key_provider', 64)->default('local');
            $table->string('key_reference', 255)->nullable();
            $table->text('wrapped_key')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->foreignId('rotated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'key_version'], 'tenant_key_version_unique');
            $table->index(['tenant_id', 'status'], 'tenant_key_status_idx');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            if (! Schema::hasColumn('tenants', 'data_residency_region')) {
                $table->string('data_residency_region', 32)->nullable()->after('currency');
            }

            if (! Schema::hasColumn('tenants', 'data_residency_locked')) {
                $table->boolean('data_residency_locked')->default(false)->after('data_residency_region');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            if (Schema::hasColumn('tenants', 'data_residency_locked')) {
                $table->dropColumn('data_residency_locked');
            }

            if (Schema::hasColumn('tenants', 'data_residency_region')) {
                $table->dropColumn('data_residency_region');
            }
        });

        Schema::dropIfExists('tenant_encryption_keys');
    }
};

