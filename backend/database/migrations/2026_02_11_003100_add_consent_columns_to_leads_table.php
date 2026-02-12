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
        Schema::table('leads', function (Blueprint $table) {
            $table->boolean('email_consent')->default(true)->after('email');
            $table->timestamp('consent_updated_at')->nullable()->after('email_consent');
            $table->index(['tenant_id', 'email_consent']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'email_consent']);
            $table->dropColumn(['email_consent', 'consent_updated_at']);
        });
    }
};
