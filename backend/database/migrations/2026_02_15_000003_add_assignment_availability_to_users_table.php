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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'settings')) {
                $table->text('settings')->nullable()->after('is_super_admin');
            }

            if (! Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('settings');
                $table->index(['tenant_id', 'last_seen_at']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_seen_at')) {
                $table->dropIndex(['tenant_id', 'last_seen_at']);
                $table->dropColumn('last_seen_at');
            }

            if (Schema::hasColumn('users', 'settings')) {
                $table->dropColumn('settings');
            }
        });
    }
};

