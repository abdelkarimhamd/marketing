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
        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('scope', 24)->default('user');
            $table->string('entity', 32)->default('global_search');
            $table->string('query')->nullable();
            $table->json('filters')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'entity']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'team_id']);
            $table->index(['tenant_id', 'scope']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
