<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_schedule_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('rescheduled_from_id')->nullable()->constrained('class_sessions')->restrictOnDelete();
            $table->date('session_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('planned_teacher_id')->nullable()->constrained('teacher_profiles')->restrictOnDelete();
            $table->foreignId('actual_teacher_id')->nullable()->constrained('teacher_profiles')->restrictOnDelete();
            $table->string('status', 30)->default('planned')->index();
            $table->string('attendance_state', 30)->default('open')->index();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('reopened_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['class_group_id', 'session_date', 'start_time'], 'class_session_occurrence_unique');
            $table->index(['session_date', 'status']);
        });

        Schema::create('attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->index();
            $table->timestamp('recorded_at');
            $table->foreignId('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['class_session_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance');
        Schema::dropIfExists('class_sessions');
    }
};
