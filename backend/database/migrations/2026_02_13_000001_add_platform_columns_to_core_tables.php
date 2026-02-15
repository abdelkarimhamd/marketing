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
        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'timezone')) {
                $table->string('timezone', 64)->default('UTC')->after('branding');
            }

            if (! Schema::hasColumn('tenants', 'locale')) {
                $table->string('locale', 12)->default('en')->after('timezone');
            }

            if (! Schema::hasColumn('tenants', 'currency')) {
                $table->string('currency', 8)->default('USD')->after('locale');
            }

            if (! Schema::hasColumn('tenants', 'sso_required')) {
                $table->boolean('sso_required')->default(false)->after('currency');
            }
        });

        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'country_code')) {
                $table->string('country_code', 8)->nullable()->after('city');
                $table->index(['tenant_id', 'country_code']);
            }

            if (! Schema::hasColumn('leads', 'locale')) {
                $table->string('locale', 12)->nullable()->after('timezone');
                $table->index(['tenant_id', 'locale']);
            }
        });

        Schema::table('messages', function (Blueprint $table) {
            if (! Schema::hasColumn('messages', 'thread_key')) {
                $table->string('thread_key')->nullable()->after('channel');
                $table->index(['tenant_id', 'thread_key']);
            }

            if (! Schema::hasColumn('messages', 'in_reply_to')) {
                $table->string('in_reply_to')->nullable()->after('provider_message_id');
            }

            if (! Schema::hasColumn('messages', 'reply_token')) {
                $table->string('reply_token')->nullable()->after('in_reply_to');
                $table->unique('reply_token');
            }

            if (! Schema::hasColumn('messages', 'reply_to_email')) {
                $table->string('reply_to_email')->nullable()->after('reply_token');
            }

            if (! Schema::hasColumn('messages', 'compliance_block_reason')) {
                $table->string('compliance_block_reason')->nullable()->after('error_message');
            }

            if (! Schema::hasColumn('messages', 'cost_estimate')) {
                $table->decimal('cost_estimate', 10, 4)->nullable()->after('compliance_block_reason');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'cost_estimate')) {
                $table->dropColumn('cost_estimate');
            }

            if (Schema::hasColumn('messages', 'compliance_block_reason')) {
                $table->dropColumn('compliance_block_reason');
            }

            if (Schema::hasColumn('messages', 'reply_to_email')) {
                $table->dropColumn('reply_to_email');
            }

            if (Schema::hasColumn('messages', 'reply_token')) {
                $table->dropUnique(['reply_token']);
                $table->dropColumn('reply_token');
            }

            if (Schema::hasColumn('messages', 'in_reply_to')) {
                $table->dropColumn('in_reply_to');
            }

            if (Schema::hasColumn('messages', 'thread_key')) {
                $table->dropIndex(['tenant_id', 'thread_key']);
                $table->dropColumn('thread_key');
            }
        });

        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'locale')) {
                $table->dropIndex(['tenant_id', 'locale']);
                $table->dropColumn('locale');
            }

            if (Schema::hasColumn('leads', 'country_code')) {
                $table->dropIndex(['tenant_id', 'country_code']);
                $table->dropColumn('country_code');
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'sso_required')) {
                $table->dropColumn('sso_required');
            }

            if (Schema::hasColumn('tenants', 'currency')) {
                $table->dropColumn('currency');
            }

            if (Schema::hasColumn('tenants', 'locale')) {
                $table->dropColumn('locale');
            }

            if (Schema::hasColumn('tenants', 'timezone')) {
                $table->dropColumn('timezone');
            }
        });
    }
};

