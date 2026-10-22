<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('progression_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('quiz_question_count')->default(10);
            $table->unsignedTinyInteger('quiz_minimum_score')->default(70);
            $table->unsignedTinyInteger('assessment_reminder_weeks')->default(8);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        // Técnica trabalhada na aula; desmarcar preenche removed_at, nunca apaga (ADR-014).
        Schema::create('class_session_techniques', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_session_id')->constrained()->restrictOnDelete();
            $table->foreignId('technique_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('marked_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['class_session_id', 'technique_item_id'], 'class_session_technique_unique');
            $table->index(['technique_item_id', 'removed_at'], 'class_session_techniques_item_index');
        });

        Schema::create('quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->foreignId('rank_id')->constrained()->restrictOnDelete();
            $table->foreignId('technique_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('statement');
            $table->text('explanation')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['association_id', 'rank_id', 'status'], 'quiz_questions_rank_index');
        });

        Schema::create('quiz_question_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_question_id')->constrained()->restrictOnDelete();
            $table->string('text', 500);
            $table->boolean('is_correct')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('quiz_releases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('rank_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('released_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->index(['student_id', 'status'], 'quiz_releases_student_index');
        });

        Schema::create('quiz_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->foreignId('rank_id')->constrained()->restrictOnDelete();
            $table->string('mode', 20);
            $table->foreignId('quiz_release_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('in_progress');
            $table->unsignedSmallInteger('question_count')->default(0);
            $table->unsignedSmallInteger('correct_count')->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->unsignedTinyInteger('minimum_score')->nullable();
            $table->boolean('passed')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'mode', 'status'], 'quiz_attempts_student_index');
        });

        Schema::create('quiz_attempt_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_attempt_id')->constrained()->restrictOnDelete();
            $table->foreignId('quiz_question_id')->constrained()->restrictOnDelete();
            $table->json('option_ids');
            $table->foreignId('chosen_option_id')->nullable()->constrained('quiz_question_options')->restrictOnDelete();
            $table->boolean('is_correct')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();
            $table->unique(['quiz_attempt_id', 'quiz_question_id'], 'quiz_attempt_question_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_answers');
        Schema::dropIfExists('quiz_attempts');
        Schema::dropIfExists('quiz_releases');
        Schema::dropIfExists('quiz_question_options');
        Schema::dropIfExists('quiz_questions');
        Schema::dropIfExists('class_session_techniques');
        Schema::dropIfExists('progression_settings');
    }
};
