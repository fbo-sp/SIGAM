<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Só inclusão: o nível atual do aluno num item é o registro mais recente (ADR-014).
        Schema::create('technique_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->foreignId('technique_item_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('level');
            $table->foreignId('teacher_profile_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('assessed_by_user_id')->constrained('users')->restrictOnDelete();
            $table->date('assessed_on');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'martial_art_id', 'technique_item_id'], 'technique_assessments_student_item_index');
            $table->index(['association_id', 'assessed_on'], 'technique_assessments_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('technique_assessments');
    }
};
