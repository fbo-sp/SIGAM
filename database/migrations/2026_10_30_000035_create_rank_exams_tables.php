<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exames de graduação, etapa 1 (decisões de Fabio em 30/10/2026): o exame, a banca, os participantes com a faixa
 * de destino e o conteúdo por faixa (grupos de nota e itens marcados, com justificativa quando nenhuma aula abordou).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rank_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->string('name', 150);
            $table->string('scope_type', 20);
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('class_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('justification')->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('minimum_average', 4, 2)->default('7.00');
            $table->boolean('class_teachers_examine')->default(true);
            $table->boolean('quiz_enabled')->default(false);
            $table->unsignedTinyInteger('quiz_question_count')->nullable();
            $table->unsignedSmallInteger('quiz_time_limit_minutes')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at')->nullable();
            $table->foreignId('opened_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();
            $table->index(['association_id', 'status'], 'rank_exams_association_status_index');
            $table->index(['dojo_id', 'status'], 'rank_exams_dojo_status_index');
        });

        Schema::create('rank_exam_examiners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rank_exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['rank_exam_id', 'teacher_profile_id'], 'rank_exam_examiner_unique');
        });

        Schema::create('rank_exam_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rank_exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_martial_art_id')->constrained()->restrictOnDelete();
            $table->foreignId('current_rank_id')->nullable()->constrained('ranks')->restrictOnDelete();
            $table->foreignId('target_rank_id')->constrained('ranks')->restrictOnDelete();
            $table->boolean('eligible')->default(true);
            $table->json('unmet')->nullable();
            $table->text('inclusion_reason')->nullable();
            $table->string('status', 20)->default('enrolled');
            $table->foreignId('added_by_user_id')->constrained('users')->restrictOnDelete();
            $table->dateTime('removed_at')->nullable();
            $table->foreignId('removed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['rank_exam_id', 'student_id'], 'rank_exam_participant_unique');
            $table->index(['student_id', 'status'], 'rank_exam_participants_student_index');
        });

        Schema::create('rank_exam_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rank_exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_rank_id')->constrained('ranks')->restrictOnDelete();
            $table->string('name', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['rank_exam_id', 'target_rank_id', 'name'], 'rank_exam_group_unique');
        });

        Schema::create('rank_exam_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rank_exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('target_rank_id')->constrained('ranks')->restrictOnDelete();
            $table->foreignId('rank_exam_group_id')->constrained()->restrictOnDelete();
            $table->string('kind', 20)->default('technique');
            $table->foreignId('technique_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('required_level')->nullable();
            $table->string('origin', 20)->nullable();
            $table->boolean('selected')->default(true);
            $table->text('justification')->nullable();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['rank_exam_id', 'target_rank_id', 'kind', 'technique_item_id'], 'rank_exam_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rank_exam_items');
        Schema::dropIfExists('rank_exam_groups');
        Schema::dropIfExists('rank_exam_participants');
        Schema::dropIfExists('rank_exam_examiners');
        Schema::dropIfExists('rank_exams');
    }
};
