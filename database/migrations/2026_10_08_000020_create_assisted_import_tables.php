<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('import_type', 30);
            $table->string('original_name');
            $table->string('stored_name');
            $table->string('storage_path');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('file_hash', 64);
            $table->string('template_version', 20)->default('1.0');
            $table->string('status', 30)->default('reviewing')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('ready_rows')->default(0);
            $table->unsignedInteger('review_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('failed_rows')->default(0);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error_summary', 1000)->nullable();
            $table->timestamps();

            $table->unique(['association_id', 'dojo_id', 'import_type', 'file_hash'], 'import_batch_file_unique');
            $table->index(['association_id', 'dojo_id', 'created_at'], 'import_batch_scope_idx');
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('row_number');
            $table->char('row_hash', 64);
            $table->json('raw_payload');
            $table->json('normalized_payload')->nullable();
            $table->string('status', 30)->index();
            $table->string('message', 1000)->nullable();
            $table->string('result_type', 60)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['import_batch_id', 'row_number'], 'import_batch_row_unique');
            $table->index(['import_batch_id', 'status', 'row_number'], 'import_row_work_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};
