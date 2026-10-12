<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dojo_role_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id', 'dojo_id', 'start_date'], 'dojo_access_period_unique');
            $table->index(['association_id', 'dojo_id', 'user_id'], 'dojo_access_scope_index');
        });
        Schema::create('teacher_association_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('active');
            $table->string('source', 30)->default('registration');
            $table->timestamps();
            $table->unique(['association_id', 'teacher_profile_id'], 'teacher_association_unique');
        });
        Schema::create('dojo_teacher_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['dojo_id', 'teacher_profile_id', 'start_date'], 'dojo_teacher_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dojo_teacher_assignments');
        Schema::dropIfExists('teacher_association_memberships');
        Schema::dropIfExists('dojo_role_assignments');
    }
};
