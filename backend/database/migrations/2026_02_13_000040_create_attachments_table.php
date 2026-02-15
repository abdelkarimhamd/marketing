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
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('entity_type', 32)->default('lead');
            $table->unsignedBigInteger('entity_id');
            $table->string('kind', 64)->default('document');
            $table->string('source', 64)->default('manual');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('storage_disk', 64);
            $table->string('storage_path', 1024);
            $table->string('original_name');
            $table->string('mime_type', 255)->nullable();
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('visibility', 32)->default('private');
            $table->string('scan_status', 32)->default('skipped');
            $table->timestamp('scanned_at')->nullable();
            $table->string('scan_engine', 64)->nullable();
            $table->text('scan_result')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'lead_id']);
            $table->index(['tenant_id', 'scan_status']);
            $table->index(['tenant_id', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
