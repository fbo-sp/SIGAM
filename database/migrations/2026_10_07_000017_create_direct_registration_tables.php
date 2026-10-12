<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registration_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['association_id', 'entity_type']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('teacher_profile_id')->nullable()->after('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_guardian_id')->nullable()->after('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->index(['association_id', 'teacher_profile_id']);
            $table->index(['association_id', 'student_guardian_id']);
        });

        Schema::create('direct_registration_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('operation', 30);
            $table->uuid('idempotency_key');
            $table->string('result_type', 30)->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->foreignId('document_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['association_id', 'operation', 'idempotency_key'], 'direct_registration_idempotency_unique');
            $table->index(['result_type', 'result_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_registration_requests');
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('student_guardian_id');
            $table->dropConstrainedForeignId('teacher_profile_id');
        });
        Schema::dropIfExists('registration_counters');
    }
};
