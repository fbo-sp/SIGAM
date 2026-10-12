<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('championships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('martial_art_id')->constrained()->restrictOnDelete();
            $table->string('name', 180);
            $table->text('description')->nullable();
            $table->string('championship_type', 30);
            $table->string('organizer', 180);
            $table->string('location_name', 255);
            $table->date('start_date');
            $table->date('end_date');
            $table->dateTime('registration_open_at');
            $table->dateTime('registration_close_at');
            $table->longText('rules_text')->nullable();
            $table->decimal('default_fee', 12, 2)->default(0);
            $table->boolean('allow_competitor')->default(true);
            $table->boolean('allow_referee')->default(false);
            $table->boolean('allow_assistant')->default(false);
            $table->string('status', 20)->default('draft')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['association_id', 'start_date', 'status'], 'championship_scope_date_status_idx');
        });

        Schema::create('championship_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained()->restrictOnDelete();
            $table->foreignId('championship_id')->constrained()->restrictOnDelete();
            $table->foreignId('person_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('dojo_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('federation_membership_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('participation_type', 20);
            $table->string('category', 120)->nullable();
            $table->unsignedTinyInteger('age_at_event')->nullable();
            $table->decimal('weight', 6, 2)->nullable();
            $table->foreignId('rank_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('team_name', 180)->nullable();
            $table->string('referee_level', 100)->nullable();
            $table->string('assistant_function', 120)->nullable();
            $table->decimal('registration_fee', 12, 2)->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->foreignId('charge_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['championship_id', 'person_id', 'participation_type'], 'championship_person_role_unique');
            $table->index(['championship_id', 'status']);
            $table->index(['student_id', 'status']);
            $table->index(['dojo_id', 'status']);
        });

        Schema::create('championship_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_registration_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('participation_confirmed')->default(false);
            $table->unsignedInteger('placement')->nullable();
            $table->string('medal', 20)->nullable();
            $table->decimal('points', 10, 2)->default(0);
            $table->boolean('walkover')->default(false);
            $table->boolean('disqualified')->default(false);
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_results');
        Schema::dropIfExists('championship_registrations');
        Schema::dropIfExists('championships');
    }
};
