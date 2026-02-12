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
        Schema::table('templates', function (Blueprint $table) {
            $table->text('body_text')->nullable()->after('content');
            $table->string('whatsapp_template_name')->nullable()->after('body_text');
            $table->json('whatsapp_variables')->nullable()->after('whatsapp_template_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['body_text', 'whatsapp_template_name', 'whatsapp_variables']);
        });
    }
};
