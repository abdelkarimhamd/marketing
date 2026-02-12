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
        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->foreignId('fallback_owner_id')
                ->nullable()
                ->after('last_assigned_user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->boolean('auto_assign_on_intake')->default(true)->after('strategy');
            $table->boolean('auto_assign_on_import')->default(true)->after('auto_assign_on_intake');
            $table->index(['tenant_id', 'auto_assign_on_intake', 'is_active'], 'assignment_rules_intake_active_idx');
            $table->index(['tenant_id', 'auto_assign_on_import', 'is_active'], 'assignment_rules_import_active_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assignment_rules', function (Blueprint $table) {
            $table->dropIndex('assignment_rules_intake_active_idx');
            $table->dropIndex('assignment_rules_import_active_idx');
            $table->dropConstrainedForeignId('fallback_owner_id');
            $table->dropColumn(['auto_assign_on_intake', 'auto_assign_on_import']);
        });
    }
};
