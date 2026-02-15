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
        Schema::table('leads', function (Blueprint $table): void {
            if (! Schema::hasColumn('leads', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('tenant_id')->constrained('brands')->nullOnDelete();
                $table->index(['tenant_id', 'brand_id']);
            }
        });

        Schema::table('templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('templates', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('tenant_id')->constrained('brands')->nullOnDelete();
                $table->index(['tenant_id', 'brand_id']);
            }
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            if (! Schema::hasColumn('campaigns', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('tenant_id')->constrained('brands')->nullOnDelete();
                $table->index(['tenant_id', 'brand_id']);
            }
        });

        Schema::table('messages', function (Blueprint $table): void {
            if (! Schema::hasColumn('messages', 'brand_id')) {
                $table->foreignId('brand_id')->nullable()->after('tenant_id')->constrained('brands')->nullOnDelete();
                $table->index(['tenant_id', 'brand_id']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            if (Schema::hasColumn('messages', 'brand_id')) {
                $table->dropForeign(['brand_id']);
                $table->dropIndex(['tenant_id', 'brand_id']);
                $table->dropColumn('brand_id');
            }
        });

        Schema::table('campaigns', function (Blueprint $table): void {
            if (Schema::hasColumn('campaigns', 'brand_id')) {
                $table->dropForeign(['brand_id']);
                $table->dropIndex(['tenant_id', 'brand_id']);
                $table->dropColumn('brand_id');
            }
        });

        Schema::table('templates', function (Blueprint $table): void {
            if (Schema::hasColumn('templates', 'brand_id')) {
                $table->dropForeign(['brand_id']);
                $table->dropIndex(['tenant_id', 'brand_id']);
                $table->dropColumn('brand_id');
            }
        });

        Schema::table('leads', function (Blueprint $table): void {
            if (Schema::hasColumn('leads', 'brand_id')) {
                $table->dropForeign(['brand_id']);
                $table->dropIndex(['tenant_id', 'brand_id']);
                $table->dropColumn('brand_id');
            }
        });
    }
};
