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
        if (Schema::hasTable('brands')) {
            return;
        }

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->string('email_from_address')->nullable();
            $table->string('email_from_name')->nullable();
            $table->string('email_reply_to')->nullable();
            $table->string('sms_sender_id', 64)->nullable();
            $table->string('whatsapp_phone_number_id', 120)->nullable();
            $table->string('landing_domain')->nullable();
            $table->json('landing_page')->nullable();
            $table->json('branding')->nullable();
            $table->json('signatures')->nullable();
            $table->text('settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->unique(['tenant_id', 'landing_domain']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
