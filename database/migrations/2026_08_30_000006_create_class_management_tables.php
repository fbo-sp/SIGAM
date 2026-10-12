<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('main_teacher_id')->nullable()->constrained('teacher_profiles')->restrictOnDelete();
            $table->string('age_group', 80)->nullable();
            $table->unsignedTinyInteger('minimum_age')->nullable();
            $table->unsignedTinyInteger('maximum_age')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['association_id', 'dojo_id', 'name']);
            $table->index(['association_id', 'status']);
        });

        Schema::create('class_group_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->string('role_type', 30)->default('assistant');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->unique(['class_group_id', 'teacher_profile_id', 'start_date'], 'class_teacher_period_unique');
        });

        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('start_time');
            $table->time('end_time');
            $table->foreignId('teacher_id')->nullable()->constrained('teacher_profiles')->restrictOnDelete();
            $table->string('location_name')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->unique(['class_group_id', 'weekday', 'start_time', 'valid_from'], 'class_schedule_version_unique');
            $table->index(['class_group_id', 'status', 'valid_from']);
        });

        Schema::create('class_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->text('exit_reason')->nullable();
            $table->timestamps();
            $table->unique(['class_group_id', 'student_id', 'start_date'], 'class_enrollment_period_unique');
            $table->index(['class_group_id', 'status']);
            $table->index(['student_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_enrollments');
        Schema::dropIfExists('class_schedules');
        Schema::dropIfExists('class_group_teachers');
        Schema::dropIfExists('class_groups');
    }
};
