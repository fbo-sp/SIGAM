<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associations', function (Blueprint $table) {
            $table->boolean('requires_minor_guardian')->default(true)->after('status');
        });

        Schema::create('dojo_person_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained()->restrictOnDelete();
            $table->string('role_type', 60);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->index(['dojo_id', 'status']);
            $table->index(['person_id', 'status']);
        });

        Schema::create('martial_arts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->unique(['association_id', 'name']);
        });

        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('display_name');
            $table->unsignedInteger('sort_order');
            $table->string('color_name')->nullable();
            $table->unsignedTinyInteger('minimum_age')->nullable();
            $table->unsignedInteger('minimum_months')->nullable();
            $table->string('status', 30)->default('active')->index();
            $table->timestamps();
            $table->unique(['martial_art_id', 'name']);
            $table->unique(['martial_art_id', 'sort_order']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained()->restrictOnDelete();
            $table->string('registration_number', 60);
            $table->date('entry_date');
            $table->foreignId('primary_dojo_id')->constrained('dojos')->restrictOnDelete();
            $table->string('status', 30)->default('pre_registration')->index();
            $table->date('exit_date')->nullable();
            $table->text('exit_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['association_id', 'registration_number']);
            $table->unique(['association_id', 'person_id']);
            $table->index(['primary_dojo_id', 'status']);
        });

        Schema::create('student_guardians', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('guardian_person_id')->constrained('people')->restrictOnDelete();
            $table->string('relationship_type', 40);
            $table->boolean('is_legal_guardian')->default(false);
            $table->boolean('is_financial_guardian')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->boolean('can_receive_notifications')->default(true);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'guardian_person_id', 'start_date'], 'student_guardian_period_unique');
            $table->index(['student_id', 'end_date']);
        });

        Schema::create('student_dojo_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'dojo_id', 'start_date'], 'student_dojo_period_unique');
        });

        Schema::create('student_martial_arts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('status', 30)->default('active');
            $table->foreignId('current_rank_id')->nullable()->constrained('ranks')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'martial_art_id', 'start_date'], 'student_martial_art_period_unique');
            $table->index(['student_id', 'status']);
        });

        Schema::create('student_rank_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_martial_art_id')->constrained()->restrictOnDelete();
            $table->foreignId('rank_id')->constrained()->restrictOnDelete();
            $table->date('achieved_at');
            $table->foreignId('awarded_by_person_id')->nullable()->constrained('people')->restrictOnDelete();
            $table->string('source_type', 40)->default('manual');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['student_martial_art_id', 'rank_id', 'achieved_at'], 'student_rank_history_unique');
        });

        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->unique()->constrained()->restrictOnDelete();
            $table->string('registration_number', 60)->nullable()->unique();
            $table->string('status', 30)->default('active')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('teacher_martial_arts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->foreignId('rank_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('federation_registration', 80)->nullable();
            $table->date('certification_expiration')->nullable();
            $table->string('status', 30)->default('active');
            $table->timestamps();
            $table->unique(['teacher_profile_id', 'martial_art_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_martial_arts');
        Schema::dropIfExists('teacher_profiles');
        Schema::dropIfExists('student_rank_history');
        Schema::dropIfExists('student_martial_arts');
        Schema::dropIfExists('student_dojo_history');
        Schema::dropIfExists('student_guardians');
        Schema::dropIfExists('students');
        Schema::dropIfExists('ranks');
        Schema::dropIfExists('martial_arts');
        Schema::dropIfExists('dojo_person_roles');

        Schema::table('associations', function (Blueprint $table) {
            $table->dropColumn('requires_minor_guardian');
        });
    }
};
